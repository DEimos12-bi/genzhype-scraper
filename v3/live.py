"""THE PLUG (step 2 of the switch, 2026-10-28): v3 takes the LIVE system's jobs and hands its videos back the same way.

The live system stays as it is: the site's director writes one job per story into the job feed (the video-feed branch,
feed.json, and the same JSON on the site), video_maker.py renders it, and api/video_receive.php takes the video, from
where the posters (YouTube, Facebook, Instagram, Bluesky) publish it. This file lets v3 stand where video_maker.py
stands, without touching anything else:

    python live.py                      the next job v3 has not made yet, in SHADOW mode (the default): the video is
                                        made and kept in drop/ (the workflow keeps it with the run and on branch v3-drop);
                                        nothing is sent to the site, nothing is posted, the live done-list is not touched
    python live.py --batch 2            two jobs in one go
    python live.py --page 4874          that job (it must be in the feed)
    python live.py --deliver            hands the video to the site like video_maker.py (step 4 only, on the owner's word):
                                        the same receive address, the same token, the same fields; then the page goes on
                                        the live done-list too, so the old maker never makes it twice

What v3 takes from a job: the page address ("link") and the live system's own finds for that story: the pictures the
site keeps for it ("visuals", with what each shows) and the clips its hunt kept ("clip_files"). They go into
material.json next to what v3 reads from the page itself; v3's eyes look at them like at any other picture or clip.
The site's receipt cards are not taken (v3 draws its own from the posts), nor a publisher's article picture (an
outlet is never shown: competitors are not promoted).

Its own done-list: .social/v3_done.txt (pages made), .social/v3_failed.txt ("page_id count": a page that failed
FAIL_CAP times is not tried again, like the live maker's parking).
"""
import base64
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
BASE = 'https://genzhype.com'
RAW_FEED = 'https://raw.githubusercontent.com/DEimos12-bi/genzhype-scraper/video-feed/feed.json'
RECEIVE_URL = os.environ.get('VIDEO_RECEIVE_URL', BASE + '/api/video_receive.php')
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0'
STATE = os.path.join(ROOT, '.social')
DONE, FAILED, LIVE_DONE = os.path.join(STATE, 'v3_done.txt'), os.path.join(STATE, 'v3_failed.txt'), os.path.join(STATE, 'video_done.txt')
FAIL_CAP = 2
# pictures of the site's store that may be shown: the site's own files (not its cover card), the subjects' own channels and posts, free photos
SHOWABLE = re.compile(r'^https://(?:genzhype\.com/(?:assets|media)/(?!covers/)|i\.ytimg\.com/|yt3\.ggpht\.com/|pbs\.twimg\.com/|(?:commons|upload)\.wikimedia\.org/|static-cdn\.jtvnw\.net/|clips-media-assets2\.twitch\.tv/)', re.I)


def log(*a):
    print(*a, flush=True)


def _get(url, timeout=30):
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    return urllib.request.urlopen(req, timeout=timeout).read()


def feed():
    """The job feed, read the way video_maker.py reads it: the checked-out video-feed branch first (VIDEO_FEED_DIR), then
    the site's own copy (needs INGEST_TOKEN), then the public branch on GitHub (this PC)."""
    d = os.environ.get('VIDEO_FEED_DIR', '')
    if d and os.path.isfile(os.path.join(d, 'feed.json')):
        return json.load(open(os.path.join(d, 'feed.json'), encoding='utf-8'))
    token = os.environ.get('INGEST_TOKEN', '').strip()
    urls = ([BASE + '/media/vfeed-%s.txt' % token, BASE + '/media/vfeed-%s.png' % token, BASE + '/media/vfeed-%s.json' % token] if token else []) + [RAW_FEED]
    for u in urls:
        try:
            b = _get(u)
        except Exception:  # noqa: BLE001
            continue
        k = b.find(b'GZJSON:')
        try:
            return json.loads((b[k + 7:] if k >= 0 else b).decode('utf-8', 'replace'))
        except Exception:  # noqa: BLE001
            continue
    raise SystemExit('live: no job feed could be read')


def lines(path):
    try:
        return [x.strip() for x in open(path, encoding='utf-8') if x.strip()]
    except OSError:
        return []


def add_line(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'a', encoding='utf-8') as f:
        f.write(text + '\n')


def failures():
    out = {}
    for x in lines(FAILED):
        pid, _, n = x.partition(' ')
        out[pid] = max(out.get(pid, 0), int(n) if n.isdigit() else 1)
    return out


def pick(jobs, wanted=None):
    done, failed = set(lines(DONE)), failures()
    for j in jobs:
        pid = str(j.get('page_id', ''))
        if wanted and pid != wanted:
            continue
        if not j.get('link') or not pid:
            continue
        if not wanted and (pid in done or failed.get(pid, 0) >= FAIL_CAP):
            continue
        return j
    return None


def who_of(url):
    p = urllib.parse.urlparse(url)
    parts = [x for x in p.path.split('/') if x]
    host = p.netloc.lower().replace('www.', '')
    plat = host.split('.')[0] if host else 'web'
    name = next((x for x in parts if x.startswith('@')), '') or (parts[0] if parts and host in ('twitch.tv', 'kick.com', 'clips.twitch.tv') and parts[0] != 'clip' else '')
    return plat, ('@' + name.lstrip('@')) if name else ''


def merge(m, job):
    """The live system's finds for this story, added to what v3 read from the page."""
    have = {im.get('url') for im in m.get('images') or []}
    titles = job.get('visual_titles') or []
    added = 0
    for i, u in enumerate(job.get('visuals') or []):
        if isinstance(u, str) and SHOWABLE.match(u) and u not in have:
            t = titles[i] if i < len(titles) and isinstance(titles[i], str) else ''
            m.setdefault('images', []).append({'url': u, 'alt': (t or 'a picture the site keeps for this story')[:200], 'store': True})
            have.add(u)
            added += 1
    seen, clips = set(), []
    for u in list((job.get('clip_files') or {}).keys()) + [c.get('url', '') if isinstance(c, dict) else str(c) for c in job.get('clips') or []]:
        base = u.split('#')[0]
        if base and base.startswith('http') and base not in seen:
            seen.add(base)
            plat, who = who_of(base)
            clips.append({'url': base, 'platform': plat, 'by': who})
    m['store_clips'] = clips[:4]
    m['live_job'] = {'page_id': str(job.get('page_id')), 'slug': job.get('slug', ''), 'hook': job.get('hook', ''), 'gravity': job.get('gravity', '')}
    return added, len(m['store_clips'])


def py(*args, timeout=None):
    return subprocess.run([sys.executable] + list(args), cwd=HERE, timeout=timeout).returncode


def make(job, budget):
    pid, link = str(job['page_id']), job['link']
    work = os.path.join(HERE, 'work', 'live-' + pid)
    os.makedirs(work, exist_ok=True)
    mfile = os.path.join(work, 'material.json')
    if not os.path.isfile(mfile):
        log('live: page %s, reading %s' % (pid, link))
        if py('material.py', link, work, timeout=240) != 0 or not os.path.isfile(mfile):
            return None, 'the page could not be read'
        m = json.load(open(mfile, encoding='utf-8'))
        pics, clips = merge(m, job)
        json.dump(m, open(mfile, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        log('live: the live system\'s finds added: %d picture(s), %d clip link(s)' % (pics, clips))
    t0 = time.time()
    while time.time() - t0 < budget:                       # make.py resumes where it stopped: called again while it asks for it
        rc = py('make.py', work, timeout=max(60, int(budget - (time.time() - t0))))
        out = [f for f in os.listdir(os.path.join(work, 'out')) if f.endswith('.mp4')] if os.path.isdir(os.path.join(work, 'out')) else []
        if out:
            return os.path.join(work, 'out', out[0]), ''
        if rc != 3:                                        # 3 = "run me again" (a step ran out of its time); anything else is final
            break
    rep = os.path.join(work, 'out', 'report.json')
    why = ''
    if os.path.isfile(rep):
        try:
            why = str(json.load(open(rep, encoding='utf-8')).get('why') or '')[:300]
        except Exception:  # noqa: BLE001
            why = ''
    return None, why or 'no video (see the log)'


def deliver(job, mp4):
    """The same hand-over as video_maker.post_video: JSON with the token, the page, the slug and the video in base64."""
    token = os.environ.get('INGEST_TOKEN', '').strip()
    if not token:
        return 'INGEST_TOKEN is not set'
    body = json.dumps({'token': token, 'page_id': int(job['page_id']), 'slug': job.get('slug') or '',
                       'video_b64': base64.b64encode(open(mp4, 'rb').read()).decode('ascii'), 'report': {'maker': 'v3'}}).encode('utf-8')
    last = ''
    for attempt in (1, 2):
        try:
            try:
                from curl_cffi import requests as cffi       # what the live maker uses: the site's firewall lets a browser-like client through
                r = cffi.post(RECEIVE_URL, data=body, headers={'Content-Type': 'application/json', 'User-Agent': UA}, impersonate='firefox', timeout=60)
                code, text = r.status_code, r.text
            except ImportError:
                req = urllib.request.Request(RECEIVE_URL, data=body, headers={'Content-Type': 'application/json', 'User-Agent': UA})
                with urllib.request.urlopen(req, timeout=60) as r:
                    code, text = r.status, r.read().decode('utf-8', 'replace')
            if code == 200 and json.loads(text).get('ok'):
                return ''
            last = 'HTTP %s %s' % (code, text[:160])
        except Exception as e:  # noqa: BLE001
            last = str(e)[:160]
        time.sleep(5)
    return last


def main():
    sys.stdout.reconfigure(encoding='utf-8')
    arg = lambda k, d=None: sys.argv[sys.argv.index(k) + 1] if k in sys.argv and sys.argv.index(k) + 1 < len(sys.argv) else d
    batch, wanted, send = int(arg('--batch', '1')), arg('--page'), '--deliver' in sys.argv
    budget = float(arg('--budget', '3000'))
    drop = os.path.join(ROOT, 'drop')
    jobs = (feed().get('posts') or [])
    log('live: %d job(s) in the feed, mode %s' % (len(jobs), 'DELIVER (the site gets the video)' if send else 'SHADOW (nothing is sent or posted)'))
    made = 0
    for _ in range(batch):
        job = pick(jobs, wanted)
        if not job:
            log('live: no job left that v3 has not made')
            break
        pid = str(job['page_id'])
        log('live: === page %s "%s"' % (pid, str(job.get('title', ''))[:80]))
        mp4, why = make(job, budget)
        if not mp4:
            n = failures().get(pid, 0) + 1
            add_line(FAILED, '%s %d' % (pid, n))
            log('live: page %s: NO VIDEO (%s); failure %d of %d' % (pid, why, n, FAIL_CAP))
            if wanted:
                break
            continue
        os.makedirs(os.path.join(drop, pid), exist_ok=True)
        out = os.path.dirname(mp4)
        for f in os.listdir(out):
            src = os.path.join(out, f)
            if os.path.isfile(src):
                with open(src, 'rb') as a, open(os.path.join(drop, pid, f), 'wb') as b:
                    b.write(a.read())
        json.dump({'page_id': pid, 'slug': job.get('slug', ''), 'title': job.get('title', ''), 'link': job['link'], 'mp4': os.path.basename(mp4), 'mode': 'deliver' if send else 'shadow',
                   'made': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}, open(os.path.join(drop, pid, 'job.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        add_line(DONE, pid)
        made += 1
        if send:
            err = deliver(job, mp4)
            if err:
                log('live: page %s: made, but the site did not take it (%s); kept in drop/' % (pid, err))
            else:
                add_line(LIVE_DONE, pid)                   # the old maker skips it from now on
                log('live: page %s: DELIVERED to the site' % pid)
        else:
            log('live: page %s: made, kept in drop/%s (shadow: not sent)' % (pid, pid))
        if wanted:
            break
    log('live: %d video(s) made' % made)
    return 0 if made or not jobs else 1


if __name__ == '__main__':
    sys.exit(main())
