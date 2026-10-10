"""FACES: the people of a story, seen. A drama is about creators and the viewer must see who is who; the posts' clips
seldom show them. Three keyless or site-keyed routes, copied from the site (video_people.php, images.php, drama_image.php):
  1. the X profile picture of every author the page cites (the syndication CDN gives it with the post; no login);
  2. Wikidata: the canonical photo (P18) of a named creator, through Wikimedia Commons, with its licence and author
     (the site's creator-or-not filter is kept: no 19th-century namesakes, no companies);
  3. the YouTube channel picture, with the site's own key, only for a channel whose title is the name and that has
     at least ten thousand subscribers.
Each face becomes a still asset of its own ("face_<slug>"), shown sharp only under words that name the person."""
import json
import os
import re
import urllib.parse
import urllib.request

import footage

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
CREATOR = re.compile(r'stream|youtub|tiktok|influencer|rapper|musician|singer|internet celebrit|content creator|gamer|podcast|comedian|personalit|social media|twitch|actor|actress|\bmodel\b|esports|player|wrestler|boxer|fighter|artist|dancer', re.I)
WRONG = re.compile(r'\b1[0-8]\d\d\b|\b19[0-5]\d\b|explorer|navigator|congress|governor|navy|footballer|bishop|painter|composer|politician|governing body|association|\bcompany\b|software|village|river|genus|species|album|film\b', re.I)


def get(url, timeout=20):
    return json.loads(urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': UA}), timeout=timeout).read().decode('utf-8', 'replace'))


def slug(name):
    return re.sub(r'[^a-z0-9]+', '', name.lower())[:16] or 'x'


def x_avatar(tweet_id):
    """-> (name, handle, picture url) of a post's author, from the post's public data."""
    try:
        j = get('https://cdn.syndication.twimg.com/tweet-result?id=%s&token=4' % tweet_id)
    except Exception:  # noqa: BLE001
        return None
    u = j.get('user') or {}
    pic = str(u.get('profile_image_url_https') or '')
    if not pic:
        return None
    return str(u.get('name') or u.get('screen_name') or ''), str(u.get('screen_name') or ''), pic.replace('_normal', '_400x400')


def wikidata_photo(name):
    """-> {url, licence, artist, qid, about} of a named creator's canonical photo, or None."""
    try:
        s = get('https://www.wikidata.org/w/api.php?action=wbsearchentities&search=%s&language=en&limit=1&format=json' % urllib.parse.quote(name))
        hit = (s.get('search') or [None])[0]
        if not hit:
            return None
        desc = str(hit.get('description') or '')
        if not CREATOR.search(desc) or WRONG.search(desc):
            return None
        c = get('https://www.wikidata.org/w/api.php?action=wbgetclaims&entity=%s&property=P18&format=json' % hit['id'])
        f = (((c.get('claims') or {}).get('P18') or [{}])[0].get('mainsnak') or {}).get('datavalue', {}).get('value')
        if not f:
            return None
        info = get('https://commons.wikimedia.org/w/api.php?action=query&titles=File:%s&prop=imageinfo&iiprop=url|extmetadata&iiurlwidth=1400&format=json' % urllib.parse.quote(f))
        ii = (next(iter(info['query']['pages'].values())).get('imageinfo') or [{}])[0]
        em = ii.get('extmetadata') or {}
        artist = re.sub(r'<[^>]+>', '', str((em.get('Artist') or {}).get('value') or '')).strip()[:40]
        return {'url': ii.get('thumburl') or ii.get('url'), 'licence': str((em.get('LicenseShortName') or {}).get('value') or ''), 'artist': artist, 'qid': hit['id'], 'about': desc}
    except Exception:  # noqa: BLE001
        return None


def youtube_avatar(name):
    """-> {url, channel, subs} for a channel titled with the name and at least 10k subscribers, with the site's key."""
    key = os.environ.get('YOUTUBE_KEY', '').strip()
    if not key:
        return None
    try:
        j = get('https://www.googleapis.com/youtube/v3/search?part=snippet&type=channel&maxResults=5&q=%s&key=%s' % (urllib.parse.quote(name), key))
        want = re.sub(r'[^a-z0-9]', '', name.lower())
        ids = [it['snippet']['channelId'] for it in j.get('items', []) if re.sub(r'[^a-z0-9]', '', it['snippet'].get('title', '').lower()) == want]
        if not ids:
            return None
        st = get('https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&id=%s&key=%s' % (','.join(ids[:5]), key))
        best = max(st.get('items', []), key=lambda it: int((it.get('statistics') or {}).get('subscriberCount') or 0), default=None)
        if not best or int((best.get('statistics') or {}).get('subscriberCount') or 0) < 10000:
            return None
        pic = ((best['snippet'].get('thumbnails') or {}).get('high') or {}).get('url', '')
        return {'url': re.sub(r'=s\d+', '=s800', pic) if pic else None, 'channel': best['snippet'].get('title', ''), 'subs': int(best['statistics'].get('subscriberCount') or 0)} if pic else None
    except Exception:  # noqa: BLE001
        return None


def find_faces(m, work, log=print):
    """Adds face assets to m['assets'] for the posts' authors and the page's people. -> the ids added."""
    A, adir, added = m.setdefault('assets', {}), os.path.join(work, 'assets'), []
    os.makedirs(adir, exist_ok=True)
    seen = {k for k, a in A.items() if k.startswith('face_')}
    names = []                                                 # (name, handle, tweet id)
    for p in m.get('posts') or []:
        names.append((str(p.get('name') or p.get('handle') or ''), str(p.get('handle') or ''), str(p.get('id') or '')))
    for n in m.get('people') or []:
        if not any(slug(n) == slug(x[0]) for x in names):
            names.append((str(n), '', ''))
    for name, handle, tid in names[:6]:
        sid = 'face_' + slug(name)
        if not name or sid in seen:
            continue
        found = None
        if tid:
            av = x_avatar(tid)
            if av and av[2]:
                found = {'url': av[2], 'credit': 'PHOTO · @%s / X' % av[1].upper(), 'src': 'their X profile picture', 'name': av[0] or name}
        if not found:
            wd = wikidata_photo(name)
            if wd and wd.get('url'):
                found = {'url': wd['url'], 'credit': ('PHOTO · %s · WIKIMEDIA COMMONS%s' % (wd['artist'], ' (%s)' % wd['licence'] if wd['licence'] else '')).upper()[:60], 'src': 'Wikimedia Commons (%s)' % wd['about'][:50], 'name': name}
        if not found:
            yt = youtube_avatar(name)
            if yt and yt.get('url'):
                found = {'url': yt['url'], 'credit': 'PHOTO · %s / YOUTUBE' % yt['channel'].upper()[:24], 'src': 'their YouTube channel picture', 'name': name}
        if not found:
            log('  %s: no photo found (X, Wikidata, YouTube)' % name)
            continue
        dest = os.path.join(adir, sid + '.jpg')
        try:
            raw = os.path.join(adir, sid + '.raw')
            footage.save(found['url'], raw, timeout=30)
            footage.run(['ffmpeg', '-y', '-v', 'error', '-i', raw, '-frames:v', '1', '-q:v', '2', dest], 40)
            os.remove(raw)
            w, h = footage.probe(dest)[:2]
            if not w or min(w, h) < 200:
                raise ValueError('too small (%dx%d)' % (w, h))
        except Exception as e:  # noqa: BLE001
            log('  %s: photo not fetched (%s)' % (name, str(e)[:60]))
            continue
        A[sid] = {'kind': 'photo', 'file': sid + '.jpg', 'person': found['name'], 'by': found['name'], 'handle': handle, 'credit': found['credit'], 'page': m.get('url', ''),
                  'about': 'photo of %s (%s)' % (found['name'], found['src']), 'w': w, 'h': h, 'dur': 0.0, 'face_of': found['name'],
                  'eyes': {'kind': 'talking', 'relevant': True, 'exact': True, 'shows': 'the face of %s' % found['name']}, 'windows': [{'t': 0.0, 'score': 4.0, 'x': 0.5, 'face': True, 'what': 'the face of %s' % found['name']}]}
        added.append(sid)
        log('  %s: %s, %dx%d (%s)' % (sid, found['name'], w, h, found['src']))
    return added
