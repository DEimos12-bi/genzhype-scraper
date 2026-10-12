"""THE INVESTIGATOR: before a word is written, an agent goes and looks, the way a person does.
The fixed pipeline read the page and the outlets the page cites, and stopped there: it never opened a link, never
asked "what is this thing the story names?", never found the subject's own page. A person cutting the video by hand
does exactly that first (the hand cut of one story found the company's own press release tying it to the subject; the
system's cut of the same page had one outlet screenshot). So this step is an AGENT LOOP, not a prompt:

  plan      a survey of facts first: what the page gives, what must be looked up and where, then a short plan
            (the planning step of smolagents: "facts given / facts to look up / plan").
  act       one tool call per step, as one JSON object: search (keyless web search), open (read a page, a post),
            note (a fact WITH the exact words of the page that says it), done.
  observe   the tool's result comes back as the next step's input; an error is an observation too, never a crash.
  verify    a note is kept only when its quote really is in the page that was opened (checked in code, not trusted),
            and what the source is (the subject's own site, a post, another publisher) is decided in code from the
            address, not by the model's word.
  stop      at "done", at the step limit, or when the agent repeats itself (the same call twice: the loop detector
            of gemini-cli, kept small).
What comes out (material.json): "evidence" (verified facts, each with its page and its words), primary pages added to
"sources" marked primary (the writer prefers them and may show them as proof), posts it found added to "posts" (the
gather step then fetches their clips). Another publisher (a news or gossip site) is only ever a signpost here: it may
say where the primary source is, it is never noted as the proof.
The step keeps its state after every answer (investigate.json) and can be stopped at a time budget and run again.
Borrowed logic, Apache-2.0: the step loop and planning step of huggingface/smolagents, the repeated-call loop check of
google-gemini/gemini-cli, the plain-language search query rule of assafelovic/gpt-researcher (see NOTICE).
usage: investigate.py <work folder> [seconds]"""
import json
import os
import re
import sys
import time

import ai
import brand
import material
import supply

MAX_STEPS = 8
SOCIAL = material.SOCIAL
STOP = set('The This That These Those There Then They Their What When Where Which While Because New Video Videos After Before With From Into Over Under About How Why Who Says Said Alleges Claims Claim '
           'Explained Meme Memes Drama Story First Last Best Top Live Stream Streamer Game Games Player Players'.split())

SYSTEM = """You are the researcher of ~NAME~'s video desk (~DESK~). A short video is about to be written from the material below. Before anyone writes, you go and CHECK, the way a careful editor does by hand. You look for exactly two things:
1. THE PRIMARY SOURCE of the story's main claim: the subject's OWN post, clip or page, where they say it themselves. Not a news site retelling it.
2. ONE FACT OF CONTEXT that makes the story make sense to someone who has never heard of it: what a thing the story names actually IS (a company, a tool, a game, a rank, an event), taken from that thing's OWN page, and any real, documented link between it and the people of the story.
News outlets and gossip sites are other publishers. Use them only as signposts to where the primary source is. They are never the proof.

You work in steps. Each step you answer with ONE JSON object and nothing else:
{"thought":"one sentence: what you need next and why","tool":"search","args":{"query":"plain words"}}
THE TOOLS
- search  {"query":"3 to 8 plain words"}            a web search. Plain natural language only: no site:, no quotes, no operators.
- open    {"url":"https://..."}                     read a page or a post (X, Reddit). You get its text. Open the most promising search hit, preferring a subject's own site.
- note    {"claim":"the fact, one plain sentence","url":"a page you opened","quote":"6 to 40 words copied EXACTLY from that page","source_is":"official | post | outlet"}
          keep a verified fact. The quote must be in the page, word for word: a machine checks it and refuses a quote that is not there.
- done    {"summary":"what you found, and what you looked for and did not find"}
RULES
- Note only what a page you OPENED says. Never note a guess, a memory or a search snippet.
- Do not repeat a call you already made. If a search gave nothing, change the words once, then move on.
- "Not found" is a good, honest answer. Stop with done when you have both things, or when you have looked and they are not there.
- Once you know what the named thing is, make ONE search with ITS name and the main person's name together: a documented link between them (a partnership, a sponsorship, an event they did together) is the strongest context a story can have. Open it only on the thing's own site or the person's own post.
- You have few steps: spend them on the claim and on the one named thing the viewer will not know."""

PLAN_ASK = """First, before any tool: survey the facts and plan (do not call a tool yet). Strict JSON:
{"given":["facts the material already gives, with who says them"],"look_up":[{"what":"a fact to verify or a thing to identify","where":"where it would be: whose own site, whose own post"}],"plan":["step 1","step 2","step 3"]}
Keep it short: at most 4 facts, 3 things to look up, 4 plan steps. The things to look up are the PRIMARY SOURCE of the main claim, and the one named thing a newcomer will not know."""


SYSTEM = SYSTEM.replace('~NAME~', brand.B['name']).replace('~DESK~', brand.B['desk'])


def squash(s):
    return re.sub(r'[^a-z0-9]', '', (s or '').lower())


def flat(s):
    return re.sub(r'\s+', ' ', re.sub(r'[^a-z0-9 ]', ' ', (s or '').lower().replace('’', "'").replace("'", ''))).strip()


def label(url):
    """The name part of a web address: "tvunetworks" for www.tvunetworks.com, "bbc" for bbc.co.uk."""
    host = re.sub(r'^https?://', '', url or '').split('/')[0].split(':')[0].lower()
    parts = [p for p in host.split('.') if p]
    if len(parts) >= 3 and parts[-2] in ('co', 'com', 'org', 'net', 'gov', 'ac'):
        return parts[-3]
    return parts[-2] if len(parts) >= 2 else (parts[0] if parts else '')


def names_of(m):
    """The names a story is about, as bare lower-case words (3 letters or more): from its title, its people, its posts' authors."""
    raw = re.findall(r"\b[A-Z][A-Za-z0-9]{2,}\b", m.get('title', '')) + [w for p in m.get('people') or [] for w in str(p).split()] + \
        [re.sub(r'[^A-Za-z0-9]', '', str(p.get('handle', ''))) for p in m.get('posts') or [] if p.get('platform', 'x') == 'x']
    body = re.sub(r"(?:\b[A-Z][A-Za-z0-9’']*[ ,:&-]+){4,}[A-Z][A-Za-z0-9’']*", ' ', m.get('page_text', ''))      # headlines out: five words in a row with capitals
    inside = lambda w: not body.strip() or re.search(r'(?<=[a-z,;] )' + re.escape(w) + r'\b', body)      # a title capitalises every word: a NAME is also written with its capital inside a sentence of the page
    return list(dict.fromkeys(squash(w) for w in raw if w not in STOP and len(squash(w)) >= 3 and inside(w)))


def outlets_of(m):
    """The other publishers a story cites: {name part of the address (without a leading "the"): address}. A page marked primary is not one."""
    out = {}
    for u in [s.get('url') or s.get('publisher', '') for s in m.get('sources') or [] if not s.get('primary')] + [e.get('source', '') for e in m.get('events') or [] if '.' in e.get('source', '') and ' ' not in e.get('source', '').strip()]:
        if not u or SOCIAL.search(u):
            continue
        lab = label(u if '//' in u else 'https://' + u)
        if len(lab) >= 3:
            out[re.sub(r'^the(?=.{4,})', '', lab)] = re.sub(r'^https?://(www\.)?', '', u).split('/')[0]
    return out


def kind_of(url, m):
    """What a page is, from its address alone: 'post' (a social platform), 'official' (its name starts with a name of the
    story: the subject's own site), 'outlet' (any other publisher)."""
    if SOCIAL.search(url):
        return 'post'
    lab = label(url)
    if lab in outlets_of(m) or 'the' + lab in outlets_of(m) or re.sub(r'^the(?=.{4,})', '', lab) in outlets_of(m):
        return 'outlet'
    return 'official' if any(lab.startswith(n) or (len(lab) >= 4 and n.startswith(lab)) for n in names_of(m)) else 'outlet'


def own_page(url, title, text, about, log=None):
    """Is this page published by the subject of the story itself? True only when a model that read it says so.
    A fan site, a wiki, a news site or a blog ABOUT them is not, whatever its address; their own site, their own
    announcement on a platform (a store page, a press release) is."""
    if SOCIAL.search(url):
        return False
    try:
        j, _ = ai.ask_json('You tell who publishes a web page. Strict JSON only.',
                           'THE STORY IS ABOUT: %s\n\nA PAGE\nAddress: %s\nTitle: %s\nIts text starts: %s\n\n'
                           'Who PUBLISHES this page? Answer "own" only when it is published by one of those people or companies THEMSELVES: their official site, their own announcement or patch notes (also when hosted on a '
                           'platform where makers post their own news, like a game\'s announcements on Steam), their press release. It then speaks as the maker ("we released", "our team") or is signed by them. '
                           'Answer "other" for a news site, a fan site, a wiki, a guide or a blog that writes ABOUT them ("the developers said", "the studio released"), even when its address carries their name.\n'
                           'JSON: {"publisher":"who publishes it, 2 to 5 words","page":"own"}   or   {"publisher":"...","page":"other"}'
                           % (', '.join(about)[:300], url, title[:160], re.sub(r'\s+', ' ', text)[:1100]), kind='light', temperature=0.1, timeout=40, max_tokens=400)
    except ai.AIError:
        return False
    own = str(j.get('page', '')).strip().lower() == 'own'
    if own:                                                    # ...and its publisher or its address carries a name of the story (a blog signed by its own authors is not "the subject")
        words = {w for a in about[:-1] or about for w in flat(a).split() if len(w) >= 4}
        where = flat(str(j.get('publisher', ''))) + ' ' + squash(re.sub(r'^https?://(www\.)?', '', url).split('/')[0])
        own = any(w in where for w in words)
    if log:
        log('  who publishes %s: %s -> %s' % (re.sub(r'^https?://(www\.)?', '', url).split('/')[0], str(j.get('publisher', '?'))[:50], 'the subject itself' if own else 'someone else'))
    return own


def read_page(url):
    """(title, text) of a page or a post. Raises on a page that does not answer."""
    plat = supply.platform(url)
    if plat == 'reddit':
        r = supply.resolve_reddit(url)
        if not r:
            raise RuntimeError(supply.LAST['err'] or 'the post did not answer')
        return 'Reddit post in %s' % r['sub'], 'A post in %s by u/%s on %s (%s comments): "%s". %s' % (
            r['sub'], r['author'], r['date'], r['comments'], r['title'], 'It carries its own video (%d s): the gather step will fetch it and hear what is said in it.' % r['seconds'] if r['video'] else
            ('It shares this address: ' + r['link']) if r['link'] else 'It has no video.')
    if plat == 'x':
        tid = re.search(r'/status/(\d+)', url)
        p = material.x_post(tid.group(1)) if tid else None
        if not p:
            raise RuntimeError('the post did not answer')
        return 'X post by %s' % p['handle'], 'A post by %s (%s) on %s, %s likes: "%s"%s' % (p['handle'], p['name'], p['date'], p['likes'], p['text'], ' It carries a video.' if any(x['type'] == 'video' for x in p['media']) else '')
    art = material.get(url, 20)
    bodies = sorted((material.text_of(b) for _, b in re.findall(r'(?is)<(article|main)[^>]*>(.*?)</\1>', art)), key=len)
    body = bodies[-1] if bodies and len(bodies[-1]) >= 400 else material.text_of(art)
    h1 = material.text_of((re.search(r'(?is)<h1[^>]*>(.*?)</h1>', art) or [None, ''])[1])
    title = h1 if len(h1.split()) >= 3 else material.text_of((re.search(r'(?is)<title[^>]*>(.*?)</title>', art) or [None, ''])[1]) or h1
    if len(h1.split()) >= 4 and 0 < body.find(h1) < len(body) * 0.4:
        body = body[body.find(h1):]
    if len(body) < 120:
        raise RuntimeError('the page has no text to read (%d characters)' % len(body))
    return title[:200], body[:14000]


def story_block(m):
    posts = '\n'.join('  - %s (%s), %s: "%s"%s' % (p['handle'], p.get('platform', 'x'), p['date'], p['text'][:300], ' [' + p['url'] + ']') for p in m.get('posts', [])) or '  (none)'
    outs = '\n'.join('  - %s: "%s" [%s]' % (s['publisher'], s['title'][:110], s['url']) for s in m.get('sources', []) if not s.get('primary')) or '  (none)'
    return ('THE STORY\nTitle: %s\nOur page (already written from the outlets below): %s\n\nThe page says:\n%s\n\nPosts the page cites:\n%s\n\nOther publishers the page cites (signposts only, already read):\n%s\n\nNames of the story: %s'
            % (m.get('title', ''), m.get('url', ''), re.sub(r'\s+', ' ', m.get('page_text', ''))[:2600], posts, outs, ', '.join(names_of(m)[:12])))


def run(m, work, log, budget=None):
    t0, sfile = time.time(), os.path.join(work, 'investigate.json')
    st = json.load(open(sfile, encoding='utf-8')) if os.path.isfile(sfile) else {'plan': None, 'steps': [], 'pages': {}, 'evidence': [], 'calls': [], 'repeats': 0, 'done': None}
    wait = min(70, ai.CONFIG['ai'].get('timeout', 100))

    def keep():
        json.dump(st, open(sfile, 'w', encoding='utf-8'), ensure_ascii=False)
        if budget and time.time() - t0 > budget:
            log('investigator: stopped at the time budget after step %d; run again' % len(st['steps']))
            raise SystemExit(3)

    story = story_block(m)
    if st['plan'] is None:
        try:
            st['plan'], by = ai.ask_json(SYSTEM, story + '\n\n' + PLAN_ASK, kind='light', temperature=0.3, timeout=wait, max_tokens=2500, patient=True)
            look = [x for x in st['plan'].get('look_up') or [] if isinstance(x, dict)][:3]
            log('investigator (%s): to look up: %s' % (by, ' | '.join('%s (%s)' % (str(x.get('what', ''))[:70], str(x.get('where', ''))[:40]) for x in look) or 'nothing'))
        except ai.AIError as e:
            log('investigator: no model answered (%s): the story is written from the page alone' % str(e)[-80:])
            st['plan'], st['done'] = {}, 'no model answered'
        keep()

    def observe(tool, args):
        """One tool call -> what the agent sees. Never raises: a failure is an observation."""
        if tool == 'search':
            q = re.sub(r'\b(site|inurl|intitle|filetype):\S+', ' ', str(args.get('query', '')))
            q = re.sub(r'["()]|\b(AND|OR|NOT)\b', ' ', q).strip()
            if len(q.split()) < 2:
                return 'A search needs 3 to 8 plain words.'
            hits = supply.exa_search(q, 6)
            if not hits:
                return 'No results (%s). Change the words once, or move on.' % (supply.LAST['err'] or 'nothing found')
            hits = sorted(hits, key=lambda h: {'official': 0, 'post': 1, 'outlet': 2}[kind_of(h['url'], m)])      # the subject's own pages first (source curation: who publishes it weighs most)
            tag = {'official': 'OWN SITE of a name of the story', 'post': 'a social post', 'outlet': 'another publisher'}
            return 'Results for "%s":\n' % q + '\n'.join('%d. [%s] %s%s\n   %s\n   %s' % (i + 1, tag[kind_of(h['url'], m)], h['title'][:110], ' (%s)' % h['published'] if h.get('published') else '', h['url'],
                                                                                 re.sub(r'\s+', ' ', h.get('text', ''))[:260]) for i, h in enumerate(hits))
        if tool == 'open':
            url = str(args.get('url', '')).strip()
            if not re.match(r'https?://', url):
                return 'open needs a full address (https://...).'
            if 'genzhype.com' in url or brand.B['site'] in url:
                return 'That is our own page: you already have its text above.'
            try:
                title, text = read_page(url)
            except Exception as e:  # noqa: BLE001
                return 'The page did not open (%s). Try another result.' % str(e)[:90]
            kind = kind_of(url, m)
            if kind != 'post':                                 # the address is a guess; who publishes the page is read from the page
                kind = 'official' if own_page(url, title, text, (m.get('people') or []) + [m.get('title', '')], log) else 'outlet'
            st['pages'][url] = {'title': title, 'text': text, 'kind': kind}
            return 'OPENED [%s] "%s" (%d characters). Its text:\n%s%s' % ({'official': 'THE SUBJECT\'S OWN PAGE: what it says is proof', 'post': 'a social post', 'outlet': 'another publisher: a signpost, not a proof'}[kind],
                                                                         title, len(text), text[:3200], ' [...]' if len(text) > 3200 else '')
        if tool == 'note':
            url, quote, claim = str(args.get('url', '')).strip(), re.sub(r'\s+', ' ', str(args.get('quote', ''))).strip(' "“”'), re.sub(r'\s+', ' ', str(args.get('claim', ''))).strip()
            pg = st['pages'].get(url) or next((v for k, v in st['pages'].items() if k.rstrip('/') == url.rstrip('/')), None)
            if not pg:
                return 'REFUSED: you have not opened that page. Open it first, then note its exact words.'
            if len(quote.split()) < 5 or len(claim.split()) < 4:
                return 'REFUSED: a note needs a claim (one sentence) and a quote of 6 to 40 words copied from the page.'
            rx = lambda q: re.search(r'[\W_]+'.join(re.escape(w) for w in re.findall(r"[A-Za-z0-9]+", q)), pg['text'], re.I)      # the same words, as the page itself writes them
            if flat(quote) in flat(pg['text']) and rx(quote):
                quote = rx(quote).group(0)
            if flat(quote) not in flat(pg['text']):
                ws, best = flat(quote).split(), ''                # the longest run of its words that the page really has
                body = flat(pg['text'])
                for a in range(len(ws)):
                    for e in range(len(ws), a + 5, -1):
                        if ' '.join(ws[a:e]) in body and e - a > len(best.split()):
                            best = ' '.join(ws[a:e]); break
                if len(best.split()) < 6:
                    return 'REFUSED: those words are not in the page. Copy a sentence exactly as the page writes it (look at the text you were given when you opened it).'
                quote = rx(best).group(0) if rx(best) else best    # kept as the page itself writes it
            if pg['kind'] == 'outlet':
                return 'NOT KEPT AS PROOF: %s is another publisher. If it says where the subject said or published this, open THAT (their post, their site). Otherwise move on.' % label(url)
            if any(flat(quote) == flat(e['quote']) for e in st['evidence']):
                return 'You already noted that.'
            st['evidence'].append({'claim': claim[:300], 'url': url, 'quote': quote[:400], 'kind': pg['kind'], 'title': pg['title'], 'publisher': re.sub(r'^https?://(www\.)?', '', url).split('/')[0]})
            return 'KEPT (%s). %d fact(s) noted so far.' % ('the subject\'s own site' if pg['kind'] == 'official' else 'a post', len(st['evidence']))
        return 'Unknown tool "%s". The tools are: search, open, note, done.' % tool

    while st['done'] is None and len(st['steps']) < MAX_STEPS:
        n = len(st['steps'])
        past = '\n\n'.join('STEP %d: %s %s\n-> %s' % (i + 1, s['tool'], json.dumps(s['args'], ensure_ascii=False)[:200], s['obs'][:(3400 if i == n - 1 else 500)]) for i, s in enumerate(st['steps'])) or '(none yet)'
        noted = '\n'.join('- %s — "%s" [%s]' % (e['claim'], e['quote'][:160], e['publisher']) for e in st['evidence']) or '(nothing yet)'
        user = ('%s\n\nYOUR PLAN: %s\n\nYOUR STEPS SO FAR:\n%s\n\nFACTS NOTED (verified):\n%s\n\nStep %d of %d.%s Your next step, as ONE JSON object:'
                % (story, json.dumps({k: st['plan'].get(k) for k in ('look_up', 'plan')}, ensure_ascii=False)[:900], past, noted, n + 1, MAX_STEPS,
                   ' This is the last step: note what you have verified, or answer done.' if n + 1 >= MAX_STEPS - 1 else ''))
        try:
            j, by = ai.ask_json(SYSTEM, user, kind='light', temperature=0.2, timeout=wait, max_tokens=1500)      # a step is a small job: the code checks every quote, the model only chooses where to look
        except ai.AIError as e:
            log('investigator: no model answered step %d (%s); it stops with what it has' % (n + 1, str(e)[-70:]))
            st['done'] = 'no model answered'
            break
        tool, args = str(j.get('tool', '')).strip().lower(), j.get('args') if isinstance(j.get('args'), dict) else {}
        if tool == 'done':
            st['done'] = re.sub(r'\s+', ' ', str(args.get('summary', '') or j.get('thought', '')))[:400] or 'done'
            log('investigator step %d (%s): done: %s' % (n + 1, by, st['done'][:160]))
            keep()
            break
        key = tool + '|' + flat(json.dumps(args, ensure_ascii=False, sort_keys=True))
        if key in st['calls']:                                 # the loop detector: the same call again is not run again
            st['repeats'] += 1
            obs = 'You already made exactly this call (see your steps above). Do something else, or answer done.'
        else:
            st['calls'].append(key)
            obs = observe(tool, args)
        st['steps'].append({'tool': tool, 'args': args, 'obs': obs, 'thought': str(j.get('thought', ''))[:200], 'by': by})
        log('investigator step %d (%s): %s %s -> %s' % (n + 1, by, tool, json.dumps(args, ensure_ascii=False)[:110], re.sub(r'\s+', ' ', obs)[:150]))
        if st['repeats'] >= 2:
            st['done'] = 'stopped: it repeated itself'
            log('investigator: it repeats itself; it stops with what it has')
        keep()
    if st['done'] is None:
        st['done'] = 'the step limit was reached'
    # ---- THE LINK. What a person checks next, by hand: "this thing the story names: does it have anything to do with the
    # people of the story?" The agent found the thing's own site; code makes the one search (the thing and the people
    # together), opens the best hit ON THAT SAME SITE, and the agent is asked once whether it documents a link.
    own = [e for e in st['evidence'] if e['kind'] == 'official']
    if own and not st.get('linked') and st['done'] != 'no model answered':
        st['linked'] = True
        thing = label(own[0]['url'])
        names = names_of(m)
        first = next((n for n in names if thing.startswith(n) or n.startswith(thing)), thing)
        proper = lambda n: next((w for w in re.findall(r"[A-Za-z0-9]+", m.get('title', '') + ' ' + m.get('page_text', '')) if squash(w) == n), n)
        site = re.split(r'\s*[|–—:-]\s+', own[0].get('title') or '')[0].strip()      # the site's own name, as its page title gives it ("TVU Networks | ...")
        site = site if 2 <= len(site) <= 40 and first in squash(site) else proper(first)
        who, run = [], []                                      # the people: names that stand side by side in the title are one person ("Kai Cenat")
        for w in re.findall(r"[A-Za-z0-9’']+", m.get('title', '')) + ['']:
            if squash(w) in names and squash(w) != first:
                run.append(w)
            elif run:
                who.append(' '.join(run)); run = []
        who = sorted(dict.fromkeys(who), key=lambda x: -len(x.split()))[:3]
        hit, q = None, ''
        for person in who:                                     # one search per person, the fullest name first
            q = '%s %s' % (site, person)
            hits = [h for h in supply.exa_search(q, 8) if label(h['url']) == thing and h['url'].rstrip('/') not in {u.rstrip('/') for u in st['pages']}]
            hit = next((h for h in hits if squash(person) in squash(h['title'] + ' ' + h.get('text', ''))), None)
            if hit:
                break
        if hit:
            try:
                title, text = read_page(hit['url'])
                st['pages'][hit['url']] = {'title': title, 'text': text, 'kind': 'official'}
                ask = ('%s\n\nYou identified %s from its own site. One more page of THAT SAME SITE came up when its name was searched together with the people of the story:\n\n"%s" (%s)\n%s\n\n'
                       'Does this page document a REAL LINK between %s and a person of the story (a partnership, an event, a product used)? If yes, answer with a note: '
                       '{"tool":"note","args":{"claim":"the link, one plain sentence with the date if the page gives one","url":"%s","quote":"6 to 40 words copied EXACTLY from the page","source_is":"official"}}. '
                       'If it does not, answer {"tool":"done","args":{"summary":"no documented link"}}.' % (story, proper(first), title, hit['url'], text[:3600], proper(first), hit['url']))
                j, by = ai.ask_json(SYSTEM, ask, kind='light', temperature=0.1, timeout=wait, max_tokens=1200)
                if str(j.get('tool', '')).lower() == 'note' and isinstance(j.get('args'), dict):
                    obs = observe('note', dict(j['args'], url=hit['url']))
                    if obs.startswith('KEPT'):
                        st['evidence'][-1]['link'] = True          # the documented link between the thing and the people: the strongest context a story has
                    log('investigator, the link (%s): searched "%s", opened %s -> %s' % (by, q, hit['url'][:90], re.sub(r'\s+', ' ', obs)[:110]))
                else:
                    log('investigator, the link (%s): searched "%s", opened %s -> no documented link' % (by, q, hit['url'][:90]))
            except (ai.AIError, RuntimeError, OSError) as e:
                log('investigator, the link: not checked (%s)' % str(e)[:80])
        elif who:
            log('investigator, the link: searched "%s": nothing on %s\'s own site names the people of the story' % (q, proper(first)))
        keep()
    # ---- what was found joins the material
    ev = st['evidence']
    m['evidence'] = ev
    have = {s['url'].rstrip('/') for s in m.get('sources') or []}
    for e in ev:
        if e['kind'] == 'official' and e['url'].rstrip('/') not in have:
            pg = st['pages'].get(e['url']) or {}
            m.setdefault('sources', []).append({'url': e['url'], 'publisher': e['publisher'], 'title': pg.get('title', '')[:200], 'excerpt': pg.get('text', '')[:5000], 'primary': True})
            have.add(e['url'].rstrip('/'))
    ids = {str(p.get('id')) for p in m.get('posts') or []}
    for url, pg in st['pages'].items():                        # a post it opened that carries a video is material: the gather step fetches it
        if pg['kind'] != 'post' or not any(e['url'] == url for e in ev):
            continue
        post = material.reddit_post(url) if supply.platform(url) == 'reddit' else (material.x_post(re.search(r'/status/(\d+)', url).group(1)) if re.search(r'/status/(\d+)', url) else None)
        if post and str(post['id']) not in ids and len(m.get('posts') or []) < 10:
            m.setdefault('posts', []).append(post); ids.add(str(post['id']))
    m['investigated'] = True
    json.dump(st, open(sfile, 'w', encoding='utf-8'), ensure_ascii=False)
    log('investigator: %d step(s), %d page(s) opened, %d fact(s) verified%s | %s' % (len(st['steps']), len(st['pages']), len(ev),
        ': ' + ' | '.join('%s [%s, %s]' % (e['claim'][:80], e['publisher'], e['kind']) for e in ev) if ev else '', st['done'][:120]))
    return ev


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    wk = sys.argv[1]
    mf = os.path.join(wk, 'material.json')
    mat = json.load(open(mf, encoding='utf-8'))
    bud = float(sys.argv[2]) if len(sys.argv) > 2 else None
    if bud:
        ai.DEADLINE = time.time() + bud + 60
    if '/slang/' in mat.get('url', ''):
        mat['investigated'] = True
        print('investigator: a word page is drawn from its own text: nothing to look up', flush=True)
    else:
        try:
            run(mat, wk, lambda *a: print(*a, flush=True), bud)
        except ai.OutOfTime as e:
            print('investigator: %s; run again' % e, flush=True)
            sys.exit(3)
    json.dump(mat, open(mf, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
