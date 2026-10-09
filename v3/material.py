"""What a story offers the director: the page's own text, the posts it cites (with their pictures and videos) and the
text of the outlets it cites.
  from_url(url)  reads the public page (a run on the owner's PC; GitHub's machines are refused by the host)
  A server feed can drop the same material.json in the work folder instead; make.py then skips this step.
usage: material.py <page url> <work folder>"""
import html
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request

UA ='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'
SOCIAL = re.compile(r'(?:x\.com|twitter\.com|tiktok\.com|reddit\.com|instagram\.com|youtube\.com|youtu\.be|facebook\.com|threads\.net|bsky\.app|twitch\.tv|kick\.com)', re.I)
NOT_OUTLETS = re.compile(r'//(?:[^/]+\.)?(?:googleapis\.com|gstatic\.com|giphy\.com|tenor\.com|vsfagency\.tech)(?:/|$)', re.I)      # fonts, GIF hosts, the site's builder: nothing to read there


def get(url, timeout=25):
    req = urllib.request.Request(url, headers={'User-Agent': UA, 'Accept-Language': 'en-US,en;q=0.9'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read().decode('utf-8', 'replace')


def text_of(fragment):
    s = re.sub(r'(?is)<(script|style|noscript|svg|form|nav|footer|aside)[^>]*>.*?</\1>', ' ', fragment)
    s = re.sub(r'(?i)<(br|/p|/div|/li|/h[1-6]|/blockquote|/tr|/section|/article)[^>]*>', '\n', s)
    s = html.unescape(re.sub(r'<[^>]+>', ' ', s))
    s = re.sub(r'[ \t\r\f\v]+', ' ', s)
    return re.sub(r'\n\s*\n+', '\n', s).strip()


def x_post(tid):
    """One public X post through X's own embed data: text, author, date, likes, pictures, the best mp4 of a video."""
    try:
        j = json.loads(get('https://cdn.syndication.twimg.com/tweet-result?id=%s&token=4&lang=en' % tid, 15))
    except Exception:  # noqa: BLE001
        return None
    if not isinstance(j, dict) or not j.get('text'):
        return None
    media = []
    for m in j.get('mediaDetails') or []:
        item = {'type': m.get('type', ''), 'image': m.get('media_url_https', '')}
        vi = m.get('video_info') or {}
        mp4 = [v for v in vi.get('variants', []) if 'mp4' in v.get('content_type', '') and v.get('url')]
        if mp4:
            item.update({'type': 'video', 'video': max(mp4, key=lambda v: v.get('bitrate', 0))['url'], 'seconds': round((vi.get('duration_millis') or 0) / 1000)})
        media.append(item)
    user = j.get('user') or {}
    return {'id': str(tid), 'url': 'https://x.com/%s/status/%s' % (user.get('screen_name', 'i'), tid), 'platform': 'x', 'handle': '@' + user.get('screen_name', '?'),
            'name': user.get('name', ''), 'text': re.sub(r'\s+', ' ', j['text']).strip(), 'date': (j.get('created_at') or '')[:10], 'likes': j.get('favorite_count') or 0,
            'reply_to': ((j.get('parent') or {}).get('user') or {}).get('screen_name', ''), 'media': media}


def examples_of(main):
    """The meme itself, as the page links it: GIPHY GIFs and the TikTok / YouTube posts that use it. For each one, where
    its picture is (GIPHY's own mp4; the public embed data of TikTok and YouTube) and the words posted with it."""
    said = {}                                                  # link -> (the name, the date) the page prints next to it
    for fig in re.findall(r'(?is)<figure[^>]*>.*?</figure>', main):
        href = re.search(r'href="(https?://[^"#]+)"', fig)
        if href:
            name = text_of((re.search(r'(?is)class="rc-who"[^>]*>(.*?)</', fig) or [None, ''])[1]) or (re.search(r'\((@[^)]+)\)', text_of(fig)) or [None, ''])[1]
            said[html.unescape(href.group(1))] = (name.strip(), (re.search(r'datetime="([^"]+)"', fig) or [None, ''])[1])
    for href, label in re.findall(r'(?is)<a[^>]+href="(https?://[^"#]+)"[^>]*>(.*?)</a>', main):      # a credit link outside a figure: "Via GIPHY (@name)"
        who = re.search(r'\((@[^)]+)\)', text_of(label))
        if who:
            said.setdefault(html.unescape(href), (who.group(1), ''))
    out = []
    for u in dict.fromkeys(html.unescape(h) for h in re.findall(r'href="(https?://[^"#]+)', main)):
        name, date = said.get(u, ('', ''))
        g = re.search(r'giphy\.com/gifs/(?:[^/?]*-)?([A-Za-z0-9]{8,})/?(?:\?|$)', u)
        t = re.search(r'tiktok\.com/@([^/?]+)/video/(\d+)', u)
        y = re.search(r'(?:youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})', u)
        ex = None
        if g:
            ex = {'kind': 'gif', 'id': g.group(1), 'by': name or 'GIPHY', 'title': ' '.join(u.rstrip('/').split('/')[-1].split('-')[:-1]), 'video': 'https://media.giphy.com/media/%s/giphy.mp4' % g.group(1)}
        elif t or y:
            j = None
            for pause in (1.5, 3, 5, 0):                       # the embed data answers "busy" now and then: asked up to four times
                try:
                    j = json.loads(get(('https://www.tiktok.com/oembed?url=' if t else 'https://www.youtube.com/oembed?format=json&url=') + urllib.parse.quote(u, safe=''), 15))
                    break
                except Exception:  # noqa: BLE001
                    time.sleep(pause)
            if not isinstance(j, dict):
                continue
            ex = {'kind': 'tiktok' if t else 'youtube', 'id': t.group(2) if t else y.group(1), 'by': '@' + t.group(1) if t else str(j.get('author_name') or name), 'title': re.sub(r'\s+', ' ', str(j.get('title') or '')).strip()[:300]}
            if t and j.get('thumbnail_url'):
                ex['image'] = j['thumbnail_url']
            elif t:
                continue
        if ex:
            out.append(dict(ex, page=u, date=date))
    return out[:10]


NOT_NAMES = set('The This That These Those There Then They Their What When Where Which While Because Some Others Other People Published Updated Status Type Category Lane Meme Memes Explained '
                'Peaking Tracking Live Seen Tone Every Each Also With From Into Over Under About After Before Since Until Through Variants Frequently Asked Related Sources Read Next Via '
                'January February March April June July August September October November December Monday Tuesday Wednesday Thursday Friday Saturday Sunday '
                'Instagram TikTok Twitter YouTube Reddit Facebook Google'.split())      # words with a capital that name no meme


def giphy_search(text, have, limit=5):
    """Moving pictures of a meme: GIPHY's own search page for the names the page uses most (the meme's characters, its
    game). YouTube and TikTok often refuse a download; a GIF of the thing itself is the motion a video about it needs.
    What comes back is only a candidate: each one is looked at, and sorted on or off topic, like the page's own examples."""
    names = [w for w in re.findall(r'\b[A-Z][a-z]{3,}\b', text[:4000]) if w not in NOT_NAMES]
    top = [w for w, _ in sorted({w: names.count(w) for w in names}.items(), key=lambda kv: -kv[1])[:3]]
    out, seen = [], set(have)
    for query in ('-'.join(top[:3]), '-'.join(top[:2])) if len(top) >= 2 else ():
        try:
            page = get('https://giphy.com/search/' + urllib.parse.quote(query.lower()), 20)
        except Exception:  # noqa: BLE001
            continue
        for slug in dict.fromkeys(re.findall(r'giphy\.com/gifs/([a-z0-9-]+-[A-Za-z0-9]{10,22})(?=["\\/? ])', page)):
            gid = slug.split('-')[-1]
            if gid in seen or len(out) >= limit:
                continue
            seen.add(gid)
            out.append({'kind': 'gif', 'id': gid, 'by': 'GIPHY', 'title': ' '.join(slug.split('-')[:-1]), 'video': 'https://media.giphy.com/media/%s/giphy.mp4' % gid,
                        'page': 'https://giphy.com/gifs/' + slug, 'date': '', 'found': 'search: ' + query})
    return out


def from_url(url):
    page = get(url)
    m = re.search(r'(?is)<main[^>]*>(.*?)</main>', page)
    main = m.group(1) if m else page
    for cut in ('class="related', 'class="next"', 'class="next ', 'class="rail-col'):      # what follows is other stories
        i = main.find(cut)
        if i > 2000:
            main = main[:i]
    title = text_of((re.search(r'(?is)<h1[^>]*>(.*?)</h1>', page) or [None, ''])[1])
    desc = html.unescape((re.search(r'(?is)<meta[^>]+name="description"[^>]+content="([^"]*)"', page) or [None, ''])[1])
    people, published = [], ''
    for blk in re.findall(r'(?is)<script type="application/ld\+json">(.*?)</script>', page):
        try:
            j = json.loads(blk)
        except Exception:  # noqa: BLE001
            continue
        for node in (j.get('@graph') if isinstance(j, dict) and '@graph' in j else [j] if isinstance(j, dict) else j):
            if not isinstance(node, dict):
                continue
            published = published or str(node.get('datePublished') or '')[:10]
            for k in ('about', 'mentions'):
                for x in node.get(k) or [] if isinstance(node.get(k), list) else [node.get(k)] if node.get(k) else []:
                    if isinstance(x, dict) and x.get('name') and x.get('@type') in ('Person', 'Organization', 'Thing', None):
                        people.append(str(x['name']))
    # the page's own "Sources" list comes first: on meme and word pages it sits below the "read next" block, after the cut above
    declared = [html.unescape(h) for blk in re.findall(r'(?is)<ol[^>]+class="sources"[^>]*>(.*?)</ol>', page) for h in re.findall(r'href="(https?://[^"#]+)', blk)]
    links = list(dict.fromkeys(declared + [html.unescape(h) for h in re.findall(r'href="(https?://[^"#]+)', main)]))
    images = []                                                # the page's own content pictures: a meme's examples first, then the cover
    for tag in re.findall(r'(?is)<img[^>]+>', main):
        src, alt = re.search(r'src="([^"]+)"', tag), re.search(r'alt="([^"]*)"', tag)
        if src and re.search(r'/assets/(memes|covers|proofs|events)/', src.group(1)):
            u = html.unescape(src.group(1))
            images.append({'url': u if u.startswith('http') else url.split('/', 3)[0] + '//' + url.split('/')[2] + u, 'alt': html.unescape(alt.group(1)) if alt else ''})
    images = sorted({i['url']: i for i in images}.values(), key=lambda i: '/covers/' in i['url'])[:6]
    post_ids = list(dict.fromkeys(re.findall(r'(?:x|twitter)\.com/[^/"\s]+/status/(\d+)', main)))      # one post = one id, however it is linked
    posts = [p for p in (x_post(t) for t in post_ids[:8]) if p]
    sources = []
    for u in links:
        if 'genzhype.com' in u or SOCIAL.search(u) or NOT_OUTLETS.search(u) or re.search(r'\.(png|jpe?g|webp|gif|svg|css|js)(\?|$)', u):
            continue
        if len(sources) >= 4:
            break
        try:
            art = get(u, 20)
        except Exception:  # noqa: BLE001
            continue
        bodies = sorted((text_of(b) for _, b in re.findall(r'(?is)<(article|main)[^>]*>(.*?)</\1>', art)), key=len)
        body = bodies[-1] if bodies and len(bodies[-1]) >= 400 else text_of(art)      # an empty <article> shell: the whole page is read instead
        h1 = text_of((re.search(r'(?is)<h1[^>]*>(.*?)</h1>', art) or [None, ''])[1])
        if len(h1.split()) >= 4 and 0 < body.find(h1) < len(body) * 0.4:
            body = body[body.find(h1):]                         # what stands above a real headline is the site's menu (a short h1 is the site's logo)
        if len(body) < 200:
            continue                                           # nothing to read: it does not take the place of an outlet that answers
        t = (h1 if len(h1.split()) >= 4 else '') or text_of((re.search(r'(?is)<title[^>]*>(.*?)</title>', art) or [None, ''])[1]) or h1
        sources.append({'url': u, 'publisher': re.sub(r'^www\.', '', u.split('/')[2]), 'title': t[:200], 'excerpt': body[:5000]})
    examples = examples_of(main) if '/meme/' in url else []
    if '/meme/' in url:                                         # the meme in motion, beyond what the page links
        examples += giphy_search(text_of(main), [e['id'] for e in examples if e['kind'] == 'gif'])
    return {'url': url, 'title': title, 'summary': desc, 'published': published, 'people': list(dict.fromkeys(people))[:12], 'page_text': text_of(main)[:9000],
            'posts': posts, 'sources': sources, 'images': images, 'examples': examples}


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    mat = from_url(sys.argv[1])
    os.makedirs(sys.argv[2], exist_ok=True)
    json.dump(mat, open(os.path.join(sys.argv[2], 'material.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('material: "%s" | page text %d chars | %d posts (%d with video, %d with a picture) | %d outlet texts (%s) | %d examples of the meme (%s) | people: %s' % (
        mat['title'][:60], len(mat['page_text']), len(mat['posts']), sum(any(m['type'] == 'video' for m in p['media']) for p in mat['posts']),
        sum(any(m['type'] == 'photo' for m in p['media']) for p in mat['posts']), len(mat['sources']), ', '.join(s['publisher'] for s in mat['sources']) or 'none',
        len(mat['examples']), ', '.join('%s by %s' % (e['kind'], e['by']) for e in mat['examples']) or 'none', ', '.join(mat['people'][:8]) or '(none listed)'))
