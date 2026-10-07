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
import urllib.request

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'
SOCIAL = re.compile(r'(?:x\.com|twitter\.com|tiktok\.com|reddit\.com|instagram\.com|youtube\.com|youtu\.be|facebook\.com|threads\.net|bsky\.app|twitch\.tv|kick\.com)', re.I)


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
    links = list(dict.fromkeys(html.unescape(h) for h in re.findall(r'href="(https?://[^"#]+)', main)))
    post_ids = list(dict.fromkeys(re.findall(r'(?:x|twitter)\.com/[^/"\s]+/status/(\d+)', main)))      # one post = one id, however it is linked
    posts = [p for p in (x_post(t) for t in post_ids[:8]) if p]
    sources = []
    for u in links:
        if 'genzhype.com' in u or SOCIAL.search(u) or re.search(r'\.(png|jpe?g|webp|gif|svg|css|js)(\?|$)', u):
            continue
        if len(sources) >= 4:
            break
        try:
            art = get(u, 20)
        except Exception:  # noqa: BLE001
            continue
        body = re.search(r'(?is)<article[^>]*>(.*?)</article>', art)
        t = text_of((re.search(r'(?is)<h1[^>]*>(.*?)</h1>', art) or [None, ''])[1]) or text_of((re.search(r'(?is)<title[^>]*>(.*?)</title>', art) or [None, ''])[1])
        sources.append({'url': u, 'publisher': re.sub(r'^www\.', '', u.split('/')[2]), 'title': t[:200], 'excerpt': text_of(body.group(1) if body else art)[:5000]})
    return {'url': url, 'title': title, 'summary': desc, 'published': published, 'people': list(dict.fromkeys(people))[:12], 'page_text': text_of(main)[:9000],
            'posts': posts, 'sources': sources}


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    mat = from_url(sys.argv[1])
    os.makedirs(sys.argv[2], exist_ok=True)
    json.dump(mat, open(os.path.join(sys.argv[2], 'material.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('material: "%s" | page text %d chars | %d posts (%d with video, %d with a picture) | %d outlet texts | people: %s' % (
        mat['title'][:60], len(mat['page_text']), len(mat['posts']), sum(any(m['type'] == 'video' for m in p['media']) for p in mat['posts']),
        sum(any(m['type'] == 'photo' for m in p['media']) for p in mat['posts']), len(mat['sources']), ', '.join(mat['people'][:8]) or '(none listed)'))
