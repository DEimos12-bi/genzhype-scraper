"""THE DESK: A STORY THAT HAS NO PAGE YET. Every other entry of this system starts from a page of the site. This one
starts from a few words ("Silent Hill Townfall patch 1.4.0 makes the game easier, fans unhappy") and builds the same
material.json a page would give, so that a fresh story can be made into a video the day it breaks:
  1. the queries: the topic itself and two more (who is involved; their own statement), plain words, no operators;
  2. the search (the keyless one of supply.py), kept to what is recent;
  3. the reading: up to six pages, one per publisher, the pages of a name of the topic first; from each page the
     posts it cites (X, Reddit, TikTok) are collected, and those are fetched like a page's posts would be;
  4. the brief: a writing model lays out title, summary, who and what, a dated timeline and the background, from those
     pages and posts ONLY, each event with its source. It takes the place of the page's text for the casting and the
     script. It is NOT trusted as proof: the fact check of a topic story reads the script against the pages and the
     posts themselves, never against the brief (material["no_page"]).
What comes out is marked no_page: the video then never promises "the full timeline is on our site" (there is no page
yet) and the closing shows the site's front page. A page of the subject's own site among the pages read is marked
primary (it is proof; another publisher's page never is). Then the usual steps follow: investigate, gather, plan ...
The step keeps its state after every stage (topic.json) and can be stopped at a time budget and run again.
usage: topic.py "<a few words>" <work folder> [seconds]        (make.py <work> --topic "<a few words>")"""
import json
import os
import re
import sys
import time

import ai
import brand
import investigate
import material
import supply

FRESH_DAYS = 30
BRIEF = """You are the desk editor of ~NAME~ (~DESK~). You get a TOPIC and everything that was found about it: pages, each with its publisher, and posts. You write the brief of this story from those sources ONLY. Strict JSON, nothing around it:
{"title":"what happened, max 70 characters, no clickbait, no question",
 "summary":"two plain sentences",
 "people":["every person, company, studio, game or product this story is about, by its exact name as the sources write it"],
 "game":"when the story is about ONE video game: its exact title as its store page writes it; otherwise an empty string",
 "events":[{"date":"YYYY-MM-DD","title":"max 90 characters","text":"two or three sentences: what happened, and who says it","source":"the publisher's address (example.com), or: X post by @handle"}],
 "background":"one paragraph for a newcomer: what the thing is, who makes it, why people care"}
RULES
- Nothing that is not in the sources. A number, a date, a name: exactly as a source gives it. Where the sources disagree, say so and name both.
- Every claim keeps who says it ("the studio's patch notes say", "players on Reddit say", "one report says").
- 2 to 6 events, in order of time. An event's source is where YOU read it.
- No opinion of your own, no prediction."""


def slug_of(text):
    return re.sub(r'-+', '-', re.sub(r'[^a-z0-9]+', '-', text.lower())).strip('-')[:70] or 'story'


BRIEF = BRIEF.replace('~NAME~', brand.B['name']).replace('~DESK~', brand.B['desk'])


def read(url):
    """(title, text, the HTML of its article) of a page."""
    html = material.get(url, 20)
    frags = sorted((b for _, b in re.findall(r'(?is)<(article|main)[^>]*>(.*?)</\1>', html)), key=len)
    frag = frags[-1] if frags and len(material.text_of(frags[-1])) >= 400 else html
    text = material.text_of(frag)
    h1 = material.text_of((re.search(r'(?is)<h1[^>]*>(.*?)</h1>', html) or [None, ''])[1])
    title = h1 if len(h1.split()) >= 3 else material.text_of((re.search(r'(?is)<title[^>]*>(.*?)</title>', html) or [None, ''])[1]) or h1
    if len(h1.split()) >= 4 and 0 < text.find(h1) < len(text) * 0.4:
        text = text[text.find(h1):]
    return title[:200], text, frag


def cited(frag):
    """The posts a page cites: X post ids, Reddit post addresses, TikTok video addresses."""
    return {'x': list(dict.fromkeys(re.findall(r'(?:x|twitter)\.com/[A-Za-z0-9_]+/status(?:es)?/(\d{8,})', frag))),
            'reddit': list(dict.fromkeys(re.findall(r'https?://(?:www\.|old\.)?reddit\.com/r/[^/"\s<>]+/comments/[a-z0-9]+(?:/[^/"\s?#<>]*)?', frag))),
            'tiktok': list(dict.fromkeys(re.findall(r'https?://(?:www\.)?tiktok\.com/@[^/"\s<>]+/video/\d+', frag)))}


def run(topic, work, log, budget=None):
    t0, sfile = time.time(), os.path.join(work, 'topic.json')
    st = json.load(open(sfile, encoding='utf-8')) if os.path.isfile(sfile) else {'topic': topic, 'queries': None, 'hits': None, 'pages': None, 'posts': None, 'brief': None}
    wait = min(80, ai.CONFIG['ai'].get('timeout', 100))

    def keep():
        json.dump(st, open(sfile, 'w', encoding='utf-8'), ensure_ascii=False)
        if budget and time.time() - t0 > budget:
            log('desk: stopped at the time budget; run again')
            raise SystemExit(3)

    today = time.strftime('%Y-%m-%d')
    names = [investigate.squash(w) for w in re.findall(r"\b[A-Z][A-Za-z0-9]{2,}\b", topic) if w not in investigate.STOP]
    if st['queries'] is None:                                  # 1. the queries
        more = []
        try:
            j, _ = ai.ask_json('You write web search queries. Strict JSON only.',
                               'A story to research: "%s" (today is %s).\nWrite TWO more search queries of 3 to 8 plain words (no operators, no quotes): one that names who or what is involved and what they did, '
                               'one that looks for their OWN statement (patch notes, a post, an announcement). JSON: {"queries":["",""]}' % (topic, today), kind='light', temperature=0.3, timeout=40, max_tokens=600)
            more = [re.sub(r'\s+', ' ', str(q)).strip() for q in j.get('queries') or [] if 2 <= len(str(q).split()) <= 10][:2]
        except ai.AIError:
            pass
        st['queries'] = list(dict.fromkeys([topic] + (more or [topic + ' official statement', topic + ' reaction'])))
        keep()
    if st['hits'] is None:                                     # 2. the search
        hits = {}
        for q in st['queries']:
            got = supply.exa_search(q, 8)
            if not got:                                        # the keyless search answers nothing now and then: asked once more
                time.sleep(4)
                got = supply.exa_search(q, 8)
            for h in got:
                hits.setdefault(h['url'].split('#')[0], h)
            log('desk: searched "%s": %d results%s' % (q, len(got), '' if got else ' (%s)' % (supply.LAST['err'] or 'nothing')))
        old = time.strftime('%Y-%m-%d', time.localtime(time.time() - FRESH_DAYS * 86400))
        st['hits'] = [h for h in hits.values() if not h.get('published') or h['published'] >= old]
        log('desk: %d results in all, %d recent (the last %d days) or undated' % (len(hits), len(st['hits']), FRESH_DAYS))
        keep()
    if not st['hits']:
        raise SystemExit('NO STORY: the search found nothing recent about "%s"' % topic)
    if st['pages'] is None:                                    # 3. the reading: one page per publisher, a name of the topic first
        own = lambda u: any(investigate.label(u).startswith(n) or (len(investigate.label(u)) >= 4 and n.startswith(investigate.label(u))) for n in names)
        cand = [h for h in st['hits'] if not material.SOCIAL.search(h['url']) and 'genzhype.com' not in h['url'] and brand.B['site'] not in h['url']]
        cand.sort(key=lambda h: (not own(h['url']), -(len(h.get('text') or '')), h.get('published') or ''))
        pages, seen, posts = [], set(), {'x': {}, 'reddit': {}, 'tiktok': {}}
        for h in st['hits']:                                   # a result that IS a post
            for kind, found in cited(h['url']).items():
                for x in found:
                    posts[kind][x] = posts[kind].get(x, 0) + 1
        for h in cand:
            lab = investigate.label(h['url'])
            if lab in seen or len(pages) >= 6:
                continue
            try:
                title, text, frag = read(h['url'])
            except Exception as e:  # noqa: BLE001
                log('desk: %s did not open (%s)' % (h['url'][:70], str(e)[:60]))
                continue
            if len(text) < 500:
                continue
            seen.add(lab)
            pages.append({'url': h['url'], 'publisher': re.sub(r'^https?://(www\.)?', '', h['url']).split('/')[0], 'title': title or h['title'], 'published': h.get('published', ''), 'text': text[:9000]})
            for kind, found in cited(frag).items():
                for x in found:
                    posts[kind][x] = posts[kind].get(x, 0) + 1
            log('desk: read %s, "%s" (%d characters)' % (pages[-1]['publisher'], pages[-1]['title'][:60], len(text)))
        st['pages'], st['cited'] = pages, {k: sorted(v, key=lambda x: -v[x]) for k, v in posts.items()}
        keep()
    if len(st['pages']) < 2:
        raise SystemExit('NO STORY: only %d page(s) could be read about "%s": too little to tell it and to check it' % (len(st['pages']), topic))
    if st['posts'] is None:                                    # the posts the pages cite
        posts = []
        for tid in st['cited']['x'][:7]:
            p = material.x_post(tid)
            if p:
                posts.append(p)
        for u in st['cited']['reddit'][:2]:
            p = material.reddit_post(u)
            if p:
                posts.append(p)
        for u in st['cited']['tiktok'][:2]:
            who, vid = re.search(r'tiktok\.com/(@[^/]+)/video/(\d+)', u).groups()
            posts.append({'id': vid, 'url': u, 'platform': 'tiktok', 'handle': who, 'name': '', 'text': '', 'date': '', 'likes': 0, 'reply_to': '', 'media': [{'type': 'video', 'video': u, 'seconds': 0, 'via': 'supply'}]})
        st['posts'] = posts
        log('desk: %d post(s) the pages cite were fetched (%s)' % (len(posts), ', '.join('%s %s' % (p.get('platform', 'x'), p['handle']) for p in posts) or 'none'))
        keep()
    if st['brief'] is None:                                    # 4. the brief
        pages = '\n\n'.join('PAGE %d, publisher %s%s: "%s"\n%s' % (i + 1, p['publisher'], ' (%s)' % p['published'] if p.get('published') else '', p['title'], p['text'][:3200]) for i, p in enumerate(st['pages'][:5]))
        posts = '\n'.join('POST by %s (%s), %s: "%s"' % (p['handle'], p.get('platform', 'x'), p.get('date', ''), p['text'][:400]) for p in st['posts'] if p.get('text')) or '(none)'
        j, by = ai.ask_json(BRIEF, 'TOPIC: %s\nTODAY: %s\n\n%s\n\nPOSTS THE PAGES CITE:\n%s\n\nWrite the brief.' % (topic, today, pages, posts), kind='text', temperature=0.3, timeout=wait, max_tokens=6000, patient=True)
        ev = [e for e in j.get('events') or [] if isinstance(e, dict) and str(e.get('title', '')).strip()][:6]
        if not str(j.get('title', '')).strip() or not ev:
            raise SystemExit('NO STORY: the desk could not lay out a timeline for "%s" from what was found' % topic)
        st['brief'] = {'title': re.sub(r'\s+', ' ', str(j['title'])).strip()[:90], 'summary': re.sub(r'\s+', ' ', str(j.get('summary', ''))).strip()[:400],
                       'people': [re.sub(r'\s+', ' ', str(x)).strip() for x in j.get('people') or [] if str(x).strip()][:10], 'game': re.sub(r'\s+', ' ', str(j.get('game') or '')).strip()[:80], 'background': re.sub(r'\s+', ' ', str(j.get('background', ''))).strip()[:1200],
                       'events': [{'date': str(e.get('date', ''))[:10], 'title': str(e['title']).strip()[:160], 'text': re.sub(r'\s+', ' ', str(e.get('text', ''))).strip()[:600], 'source': str(e.get('source', '')).strip()[:60], 'post': '', 'proof': '', 'proof_caption': ''} for e in ev],
                       'by': by}
        log('desk (%s): "%s" | %d events | about: %s' % (by, st['brief']['title'], len(ev), ', '.join(st['brief']['people'][:6])))
        keep()
    b = st['brief']
    if st.get('about') is None and st['posts']:                # which posts are about THIS story
        first = min([e['date'] for e in b['events'] if re.fullmatch(r'\d{4}-\d\d-\d\d', e['date'])] or [today])
        floor = time.strftime('%Y-%m-%d', time.localtime(time.mktime(time.strptime(first, '%Y-%m-%d')) - 30 * 86400))
        fresh = [i for i, p in enumerate(st['posts']) if not p.get('date') or p['date'] >= floor]
        keep_ = fresh
        try:
            j, _ = ai.ask_json('You sort posts. Strict JSON only.',
                               'THE STORY: %s\n%s\nIts events: %s\n\nPOSTS that pages about it cite:\n%s\n\n'
                               'Which posts are about THIS story, the same event (the same version, the same day or the days after, the same people)? A post about an EARLIER update, another game or another matter is not. '
                               'JSON: {"about":[0,2]} (the numbers; an empty list when none is).'
                               % (b['title'], b['summary'], '; '.join('%s %s' % (e['date'], e['title']) for e in b['events']),
                                  '\n'.join('%d. %s (%s) %s: "%s"' % (i, st['posts'][i]['handle'], st['posts'][i].get('platform', 'x'), st['posts'][i].get('date', ''), st['posts'][i]['text'][:300]) for i in fresh)),
                               kind='light', temperature=0.1, timeout=40, max_tokens=400)
            keep_ = [int(i) for i in j.get('about') or [] if str(i).isdigit() and int(i) in fresh]
        except ai.AIError:
            pass
        st['about'] = keep_
        gone = [st['posts'][i]['handle'] for i in range(len(st['posts'])) if i not in keep_]
        log('desk: %d of %d cited post(s) are about this story%s' % (len(keep_), len(st['posts']), ' (left out: %s)' % ', '.join(gone) if gone else ''))
        keep()
    posts_kept = [p for i, p in enumerate(st['posts']) if i in (st.get('about') or [])] if st['posts'] else []
    text = '%s\n%s\n\nBackground\n%s\n\nThe timeline\n%s' % (b['title'], b['summary'], b['background'], '\n'.join('%s  %s\n%s [%s]' % (e['date'], e['title'], e['text'], e['source']) for e in b['events']))
    m = {'url': brand.B['url'].rstrip('/') + '/topic/%s/' % slug_of(b['title']), 'no_page': True, 'topic': topic, 'game': b.get('game', ''), 'title': b['title'], 'summary': b['summary'], 'published': today, 'people': b['people'], 'page_text': text,
         'posts': posts_kept, 'sources': [], 'images': [], 'examples': [], 'events': b['events']}
    if st.get('own') is None:                                  # the subject's own page is proof; another publisher's is a signpost: read from the page, not guessed from its address
        st['own'] = [bool(investigate.own_page(p['url'], p['title'], p['text'], b['people'] + [b['title']], log)) for p in st['pages'][:5]]
        keep()
    for p, own in zip(st['pages'][:5], st['own']):
        m['sources'].append({'url': p['url'], 'publisher': p['publisher'], 'title': p['title'][:200], 'excerpt': p['text'][:5000], **({'primary': True} if own else {})})
    json.dump(m, open(os.path.join(work, 'material.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    log('material (from a topic, no page yet): "%s" | %d posts (%d with video) | %d pages read (%s) | %d of them the subject\'s own | %d timeline events' % (
        m['title'][:60], len(m['posts']), sum(any(x['type'] == 'video' for x in p['media']) for p in m['posts']), len(m['sources']), ', '.join(s['publisher'] for s in m['sources']),
        sum(1 for s in m['sources'] if s.get('primary')), len(m['events'])))
    return m


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    bud = float(sys.argv[3]) if len(sys.argv) > 3 else None
    if bud:
        ai.DEADLINE = time.time() + bud + 70
    os.makedirs(sys.argv[2], exist_ok=True)
    try:
        run(sys.argv[1], sys.argv[2], lambda *a: print(*a, flush=True), bud)
    except ai.OutOfTime as e:
        print('desk: %s; run again' % e, flush=True)
        sys.exit(3)
    except ai.AIError as e:
        print('desk: no model answered (%s)' % str(e)[-90:], flush=True)
        sys.exit(1)
