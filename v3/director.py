"""THE DIRECTOR: reads one story's material and writes the plan of its video (plan.json), with nobody in the loop.
  1. the AI writes the script and says what is on screen for each line (a small fixed vocabulary of graphics);
  2. code checks every rule; what it can fix it fixes, what it cannot goes back to the AI with the reason (2 rounds);
  3. a plan that still breaks a hard rule is refused: no video is better than a wrong one.
Where each clip is cut is decided later, after the clips were looked at (eyes.py, shots.py).
usage: director.py <work folder>"""
import json
import os
import re
import sys
import time

import ai

HERE = os.path.dirname(os.path.abspath(__file__))
CFG = ai.CONFIG
LIGHT, CARDS = ('stamp', 'chip', 'sub'), ('rows', 'quote', 'receipt', 'blocks')
BANNED = ['official source', 'fans ask', 'fans are asking', 'in conclusion', 'stay tuned', 'buckle up', 'let that sink in', 'you won\'t believe', 'dive in', 'delve',
          'the internet is', 'netizens', 'swipe up', 'smash that', 'in this video', 'welcome back',
          'our page', 'unverified', 'primary source', 'gen z hype', 'genzhype', 'gen-z hype']      # the video tells the story, never our own checking
# words that state a crime, a lie or a death as fact: the line then has to say who says it
HEAVY = re.compile(r'\b(stole|stolen|theft|thief|fraud|scam|scammed|lied|liar|arrested|guilty|convicted|assaulted|abuse[ds]?|groom\w*|rape\w*|murder\w*|died|'
                   r'overdos\w*|suicide|racist|harass\w*|cheat(?:ed|er|ing)|plagiari\w*|embezzl\w*)\b', re.I)
SAYS = re.compile(r'\b(says?|said|claims?|claimed|alleg\w*|reportedly|according|accus\w*|admit\w*|denie[sd]|deny|told|wrote|posted|quote|reports?|reported|police|court|'
                  r'lawsuit|filing|statement|apolog\w*|confirm\w*|call(?:s|ed)? it)\b', re.I)
# a story about a death, an arrest, sexual violence or a minor waits for the owner (read on the page's title and summary:
# game words like "killed a combo" must not hold a patch-notes video)
SENSITIVE = re.compile(r'\b(died|dies|dead at|death of|passed away|found dead|murder\w*|suicide|overdos\w*|arrest\w*|charged with|rape\w*|sexual\w*|groom\w*|underage|minors?|'
                       r'child abuse|assault\w*|shooting|stabb\w*|domestic violence)\b', re.I)


def norm(s):
    return re.sub(r'[^a-z0-9€$%]+', ' ', (s or '').lower().replace('’', "'").replace("'", '')).strip()


def tokens(text):
    return [w.strip('.,?!:;"“”()').lower().replace('’', "'") for w in text.split()]


def words(text):
    return len(text.split())


def corpus(m):
    return norm(' '.join([m.get('page_text', ''), m.get('summary', '')] + [p['text'] for p in m.get('posts', [])] + [s.get('excerpt', '') + ' ' + s.get('title', '') for s in m.get('sources', [])]))


def assets_of(m):
    """What can be put on screen before any search: the videos and pictures of the story's own posts."""
    out = {}
    for i, p in enumerate(m.get('posts', [])):
        for md in p['media']:
            if md['type'] == 'video' and md.get('video') and 'clip%d' % i not in out:
                out['clip%d' % i] = {'kind': 'clip', 'post': i, 'url': md['video'], 'seconds': md.get('seconds'), 'credit': 'CLIP · %s / X' % p['handle'].upper(), 'about': p['text'][:240], 'by': p['handle'], 'page': p['url']}
            elif md['type'] == 'photo' and md.get('image') and 'photo%d' % i not in out:
                out['photo%d' % i] = {'kind': 'photo', 'post': i, 'url': md['image'], 'credit': 'IMAGE · %s / X' % p['handle'].upper(), 'about': p['text'][:240], 'by': p['handle'], 'page': p['url']}
    for i, im in enumerate(m.get('images') or []):             # the page's own pictures (a meme's examples, the cover)
        out['page%d' % i] = {'kind': 'photo', 'url': im['url'], 'credit': '', 'about': im.get('alt', ''), 'by': 'the page', 'page': m.get('url', '')}
    return out


SYSTEM = """You are the director of GenZHype's TikTok videos (vertical, 61 to 75 seconds, one male voice-over, footage under everything). GenZHype is "the receipts, not the gossip": creator and gaming stories told with the proof on screen.
You write ONE plan as strict JSON. A machine builds the video from it with nobody checking, so follow the format exactly.

HOW THE VIDEO WORKS
- The viewer decides in 2 seconds. Line 1 is the hook: the single most surprising TRUE fact of the story, with a number or a name, in 2 or 3 short sentences. It opens on real footage, with the key number or word stamped on screen.
- Then: who is involved (one line), the evidence beat by beat (each beat = one line, each with its proof: a post, a quote, a number), the turn ("but", "then", "same night"), what is at stake.
- The second-to-last thing is a VOTE: one question, two answers of ONE or TWO words each, taken from the story's real conflict (never "yes / no"). The line says "Comment one word." and both answers.
- Do NOT write the closing line that sends to the website; the machine adds it.

THE VOICE (study the examples: this exact rhythm)
- Short sentences. One idea each. Names and numbers. Present tense where possible. No filler, no hype words, no questions to "fans", no "official source".
- %(wmin)d to %(wmax)d spoken words in total, in 8 to 9 lines of 12 to 32 words. Count them.
- Numbers are written as spoken words in "text" ("three hundred thousand euros", "twenty-fourteen"), and each one gets a "caps" entry so the caption shows digits: {"say":"twenty-two million","show":"22 million"}.
- Do not hedge every sentence. Say who reports a claim ONCE, where it first appears ("Kotaku reports", "the patch notes say"), then tell it plainly. Line 1 starts with the fact itself, never with an outlet's name. Never say "end quote".
- Tell the story, not our work: never mention GenZHype's checking, "we found", "unverified", "our page".
- TRUTH: use only facts that are in the material. Anything disputed, or about wrongdoing, is said with who says it ("the agent says", "police say", "reportedly", "according to Kotaku"). A quote must be the source's real words (translated quotes: say "translated"). Never guess a person's gender: use the name or "they".

ON SCREEN, for each line
- "show": what footage is under the line. {"asset":"clip0"} / {"asset":"photo1"} = a clip or picture from the story's own posts (ids listed in the material). {"asset":"hunt1"} = footage you ask for in "hunts". "mode":"sharp" = the footage is the point (hook, a person talking, the thing itself); "mode":"under" = blurred behind a card.
  A line WITHOUT a card should be "sharp". A clip of a person may only be shown sharp on a line about THAT person (the clip's own post tells you who is in it).
- "hunts": up to %(hunts)d footage requests for things the words describe but the posts do not show: the game's gameplay or trailer, the event, the arena, the product. Each: {"id":"hunt1","query":"4 to 7 search words naming the exact thing","must_show":"what the picture must show","game":"only when the footage wanted is a video game: its exact title, nothing else"}. A hunt with "game" gets that game's official trailer (always found, always the right game); use one for every game the story is about. Ask for enough: every line needs footage, a video with one clip is refused. Hunt THINGS (the game, the event, the arena, the product, a trailer), not a named person's face: a search cannot promise who is in the picture. Never hunt a private person's home, a victim, a mugshot.
- "overlays": the graphics, each with "on": ONE word of that line (exactly as written) on which it appears. Use few and big:
  {"k":"stamp","t":"22.8M VIEWS"}          the one number or word of the line, huge (max 12 characters)
  {"k":"chip","t":"KOTAKU · OCT 2"}        a small label (max 30 characters)
  {"k":"sub","t":"BUILT IN HOURS"}         a second line under the stamp (max 22 characters)
  {"k":"tag","name":"Jetpack Cat","role":"SUPPORT HERO · OVERWATCH"}   a name plate, the first time a person or character is shown or named
  {"k":"rows","rows":[{"t":"MISSING BLOCKS","on":"Missing","tone":"red"},{"t":"INVISIBLE WALLS","on":"Invisible"}]}   2 to 4 short facts appearing one by one (max 26 characters each)
  {"k":"quote","t":"real words of a source, max 90 characters","who":"NAME · VIA OUTLET","label":"WHAT THIS IS"}
  {"k":"receipt","post":"post1","mark":"exact words copied from that post, to highlight","translate":"English, only if the post is not in English"}   the post itself as a card
  {"k":"blocks","items":[{"label":"IN GACHA SPINS","big":"$50–$100","on":"fifty"},{"label":"TO BUY IT OUTRIGHT","big":"$700","on":"seven"}]}   two things compared
  At most ONE of rows / quote / receipt / blocks per line, plus at most two of stamp / chip / sub, plus tags. All text in CAPITALS except quotes.
- Show the proof: every post that matters appears once as a "receipt"; the strongest quote appears as a "quote".

OUTPUT: strict JSON, nothing around it:
{"angle":"the conflict in one sentence","lines":[{"id":"hook","text":"...","show":{"asset":"clip0","mode":"sharp"},"overlays":[{"k":"stamp","t":"...","on":"word"}],"caps":[{"say":"...","show":"..."}]}],
 "vote":{"question":"max 22 characters","a":{"word":"ONE WORD","sub":"that side in 3 words"},"b":{"word":"ONE WORD","sub":"that side in 3 words"}},
 "people":[{"name":"","role":""}],"hunts":[{"id":"hunt1","query":"","must_show":""}],"stock":"3 words for neutral background footage of this story's world",
 "post":{"caption":"max 150 characters, ends with the vote","hashtags":["8 to 11 lowercase tags without #"],"pinned":"the vote again, then: receipts on genzhype.com (link in bio)"}}
The last line's id must be "vote"."""


def prompt_for(m):
    slug = m['url'].rstrip('/').split('/')[-1]
    ex = [e for e in json.load(open(os.path.join(HERE, 'examples.json'), encoding='utf-8')) if e['slug'] != slug][:2]
    examples = '\n\n'.join('EXAMPLE (%s). Vote: %s or %s.\n%s' % (e['about'], e['vote'][0], e['vote'][1], '\n'.join('%d. %s' % (i + 1, l) for i, l in enumerate(e['lines']))) for e in ex)
    a = assets_of(m)
    alist = '\n'.join('  %s: %s%s. Its post says: "%s"' % (k, 'video, %ss' % v.get('seconds') if v['kind'] == 'clip' else 'picture', ' from ' + v['by'], v['about'][:200]) for k, v in a.items()) or '  (none: every line needs a hunt)'
    plist = '\n'.join('  post%d: %s (%s), %s, %s likes%s: "%s"' % (i, p['handle'], p['name'], p['date'], p['likes'], ', replying to @' + p['reply_to'] if p.get('reply_to') else '', p['text'][:500]) for i, p in enumerate(m.get('posts', []))) or '  (none)'
    slist = '\n\n'.join('OUTLET %s: "%s"\n%s' % (s['publisher'], s['title'], s['excerpt'][:2600]) for s in m.get('sources', [])) or '(none fetched)'
    user = ('%s\n\nTHE STORY\nTitle: %s\nPage: %s\nPublished: %s\n\nOUR PAGE (already fact-checked; its attributions are the safe wording):\n%s\n\nTHE POSTS THE PAGE CITES (ids for receipts):\n%s\n\n'
            'CLIPS AND PICTURES AVAILABLE NOW (ids for "show"):\n%s\n\nWHAT THE OUTLETS WROTE:\n%s\n\nWrite the plan.'
            % (examples, m['title'], m['url'], m.get('published', ''), m['page_text'][:6500], plist, alist, slist))
    return SYSTEM % {'wmin': CFG['length']['words_min'], 'wmax': CFG['length']['words_max'], 'hunts': CFG['footage']['max_hunts']}, user


def fix_and_check(p, m):
    """Returns (plan with every mechanical fix applied, [reasons the AI must fix], [hard reasons the plan is refused])."""
    soft, hard = [], []
    cor, have = corpus(m), assets_of(m)
    lines = [l for l in p.get('lines', []) if isinstance(l, dict) and str(l.get('text', '')).strip()]
    if not lines:
        return p, [], ['the plan has no lines']
    lines = [l for l in lines if l.get('id') != 'site']
    lines[-1]['id'] = 'vote'
    seen = set()
    for i, l in enumerate(lines):
        base = re.sub(r'[^a-z0-9]', '', str(l.get('id') or '').lower()) or 'l%d' % i
        l['id'] = base if base not in seen else '%s%d' % (base, i)
        seen.add(l['id'])
        l['text'] = re.sub(r'\s+', ' ', str(l['text'])).strip()
    total = sum(words(l['text']) for l in lines)
    lo, hi = CFG['length']['words_min'], CFG['length']['words_max']
    if total < lo:
        soft.append('only %d spoken words: write %d to %d (add one more evidence beat from the material, do not pad)' % (total, lo, hi))
    if total > hi + 12:
        soft.append('%d spoken words: cut to %d at most' % (total, hi))
    if not 7 <= len(lines) <= 10:
        soft.append('%d lines: write 8 or 9' % len(lines))
    hunts = {h['id']: h for h in (p.get('hunts') or [])[:CFG['footage']['max_hunts']] if isinstance(h, dict) and re.fullmatch(r'hunt\d+', str(h.get('id', ''))) and len(str(h.get('query', '')).split()) >= 2}
    p['hunts'] = list(hunts.values())
    vote = p.get('vote') or {}
    va, vb = norm((vote.get('a') or {}).get('word', '')), norm((vote.get('b') or {}).get('word', ''))
    if not va or not vb or {va, vb} & {'yes', 'no', 'maybe', 'not enough info'}:
        soft.append('the vote needs two answers of one or two words taken from the conflict (never yes / no)')
    elif va not in norm(lines[-1]['text']) or vb not in norm(lines[-1]['text']) or 'comment' not in norm(lines[-1]['text']):
        soft.append('the last line must say "Comment one word." and both vote answers: "%s" and "%s"' % (vote['a']['word'], vote['b']['word']))
    for i, l in enumerate(lines):
        t, low, toks = l['text'], l['text'].lower(), tokens(l['text'])
        for b in BANNED:
            if b in low:
                soft.append('line %d uses "%s": say the fact itself' % (i + 1, b))
        if words(t) > 36:
            soft.append('line %d has %d words: split it or cut it to 32' % (i + 1, words(t)))
        if HEAVY.search(t) and not SAYS.search(t) and '"' not in t:
            soft.append('line %d states "%s" as a fact: say who says it' % (i + 1, HEAVY.search(t).group(0)))
        if re.search(r'\d', t):
            soft.append('line %d has digits in the spoken text ("%s"): write numbers as spoken words and put the digits in "caps"' % (i + 1, re.search(r'\S*\d\S*', t).group(0)))
        show = l.get('show') if isinstance(l.get('show'), dict) else {}
        asset = str(show.get('asset') or 'auto')
        if asset not in have and asset not in hunts:
            asset = 'auto'
        l['show'] = {'asset': asset, 'mode': 'under' if show.get('mode') == 'under' else 'sharp'}
        ov, kept, card, light = [o for o in (l.get('overlays') or []) if isinstance(o, dict)], [], 0, 0
        for o in ov:
            k = o.get('k')
            shown = json.dumps(o, ensure_ascii=False).lower()
            if any(b in shown for b in ('our page', 'our read', 'unverified', 'primary source', 'gen z hype', 'genzhype', 'gen-z hype')):
                continue                                       # a graphic about our own checking is not part of the story: it is not drawn
            on = tokens(str(o.get('on') or ''))[:1]
            o['on'] = on[0] if on and on[0] in toks else ''
            if k in LIGHT and str(o.get('t', '')).strip() and light < 2:
                o['t'] = str(o['t']).strip().upper()[:34]
                light += 1; kept.append(o)
            elif k == 'tag' and str(o.get('name', '')).strip():
                if norm(o['name']) in cor:
                    o['role'] = str(o.get('role', '')).upper()[:40]
                    kept.append(o)
            elif k in CARDS and not card:
                if k == 'rows':
                    rows = [r for r in (o.get('rows') or []) if isinstance(r, dict) and str(r.get('t', '')).strip()][:4]
                    for r in rows:
                        r['t'] = str(r['t']).strip().upper()[:30]
                        ron = tokens(str(r.get('on') or ''))[:1]
                        r['on'] = ron[0] if ron and ron[0] in toks else ''
                    if len(rows) < 2:
                        continue
                    o['rows'] = rows
                elif k == 'quote':
                    q = norm(str(o.get('t', '')))
                    if len(q) < 12 or not str(o.get('who', '')).strip():
                        continue
                    if q[:50] not in cor and 'translated' not in str(o.get('who', '')).lower() + str(o.get('label', '')).lower():
                        soft.append('line %d shows a quote no source contains ("%s"): copy real words or drop it' % (i + 1, str(o['t'])[:50]))
                        continue
                    o['t'] = str(o['t']).strip()[:110]
                elif k == 'receipt':
                    mm = re.fullmatch(r'post(\d+)', str(o.get('post', '')))
                    if not mm or int(mm.group(1)) >= len(m.get('posts', [])):
                        continue
                    ptxt = m['posts'][int(mm.group(1))]['text']
                    mark = str(o.get('mark') or '').strip()
                    if mark and mark not in ptxt:                 # keep the longest run of its words that the post really has
                        ws, best = mark.split(), ''
                        for a in range(len(ws)):
                            for b in range(len(ws), a + 2, -1):
                                cand = ' '.join(ws[a:b])
                                if cand in ptxt and len(cand) > len(best):
                                    best = cand
                        mark = best
                    o['mark'] = mark
                elif k == 'blocks':
                    items = [x for x in (o.get('items') or []) if isinstance(x, dict) and str(x.get('big', '')).strip()][:2]
                    for x in items:
                        x['big'] = str(x['big']).strip().upper()[:9]; x['label'] = str(x.get('label', '')).upper()[:30]
                        xon = tokens(str(x.get('on') or ''))[:1]
                        x['on'] = xon[0] if xon and xon[0] in toks else ''
                    if len(items) < 2:
                        continue
                    o['items'] = items
                card += 1; kept.append(o)
        l['overlays'] = kept
        l['show']['mode'] = 'under' if card else 'sharp'      # decided here, not by the AI: footage is sharp unless a card needs to be read over it
        caps = []
        for c in l.get('caps') or []:
            if isinstance(c, dict) and str(c.get('say', '')).strip() and str(c.get('show', '')).strip() and norm(c['say']) in norm(t):
                caps.append({'say': str(c['say']).strip(), 'show': str(c['show']).strip()[:14]})
        l['caps'] = caps
    if not any(o.get('k') == 'stamp' for o in lines[0]['overlays']):
        soft.append('line 1 needs a "stamp": the key number or word of the hook')
    if sum(1 for l in lines for o in l['overlays'] if o.get('k') in ('receipt', 'quote')) == 0:
        soft.append('no proof on screen: show at least one post as a "receipt" or one real quote as a "quote"')
    if not have and not hunts:
        hard.append('no footage: the story\'s posts have no video or picture and the plan asks for none')
    p['lines'] = lines
    p['words'] = total
    flat = m.get('title', '') + ' ' + m.get('summary', '')
    p['sensitive'] = {'flag': bool(SENSITIVE.search(flat)), 'why': (SENSITIVE.search(flat).group(0) if SENSITIVE.search(flat) else '')}
    return p, soft, hard


def add_site_line(p):
    spoken = CFG['brand']['spoken']
    p['lines'].append({'id': 'site', 'text': 'The full timeline, and every receipt, is on %s. Link in bio.' % spoken, 'show': {'asset': 'auto', 'mode': 'under'}, 'overlays': [], 'caps': [{'say': spoken, 'show': spoken.replace(' ', '')}]})
    return p


def unsupported(plan, m):
    """A second, adversarial reading: every spoken sentence and on-screen text against the material. Returns the reasons to fix."""
    def screen(o):
        return ' '.join([str(o.get('t') or o.get('name') or '')] + [r.get('t', '') for r in o.get('rows', [])] + [x.get('label', '') + ' ' + x.get('big', '') for x in o.get('items', [])]).strip()
    script = '\n'.join('%d. %s  [on screen: %s]' % (i + 1, l['text'], ' / '.join(screen(o) for o in l['overlays'])) for i, l in enumerate(plan['lines']))
    mat = 'OUR PAGE:\n%s\n\nPOSTS:\n%s\n\nOUTLETS:\n%s' % (m['page_text'][:6500], '\n'.join('%s: %s' % (p['handle'], p['text'][:500]) for p in m.get('posts', [])), '\n\n'.join(s['excerpt'][:2600] for s in m.get('sources', [])))
    sys_ = ('You are a strict fact checker. You get a short video script and the ONLY material it may use. List every statement in the script (spoken or on screen) that the material does not support: '
            'invented facts, numbers or names that differ, superlatives and predictions the material does not make ("the best in the world", "they will lose"), a claim about wrongdoing stated without who says it, '
            'a guessed gender. A fair summary of what the material says is supported. Questions and the vote are not claims. Strict JSON only: {"problems":[{"line":1,"text":"the words","why":"short"}]} (an empty list if all is supported).')
    try:
        j, _ = ai.ask_json(sys_, 'MATERIAL\n%s\n\nSCRIPT\n%s' % (mat, script), temperature=0.1, timeout=ai.CONFIG['ai'].get('timeout', 100), max_tokens=6000)
    except ai.AIError:
        return None                                           # no checker answered: said in the report, the plan is kept
    return ['line %s says "%s": not in the material (%s); say only what the material says, or cut it' % (p.get('line'), str(p.get('text', ''))[:70], str(p.get('why', ''))[:80])
            for p in j.get('problems', []) if isinstance(p, dict)][:8]


def direct(m, log=print, work=None, budget=None):
    """The plan, in up to three rounds of write -> code checks -> fact check -> repair. With a work folder the progress is
    kept after every AI answer (plan_progress.json): a run that is stopped, or a caller with a time limit (budget, in
    seconds: exit code 3 = run again), continues where it was instead of paying for the same answers twice."""
    system, user = prompt_for(m)
    t0, wait = time.time(), ai.CONFIG['ai'].get('timeout', 100)
    ck = os.path.join(work, 'plan_progress.json') if work else None
    st = json.load(open(ck, encoding='utf-8')) if ck and os.path.isfile(ck) else {'round': 0, 'plan': None, 'model': '', 'facts': None, 'facts_for': -1}

    def keep():
        if ck:
            json.dump(st, open(ck, 'w', encoding='utf-8'), ensure_ascii=False)
        if budget and time.time() - t0 > budget:
            log('director: stopped at the time budget after round %d; run again' % (st['round'] + 1))
            raise SystemExit(3)

    if st['plan'] is None:
        st['plan'], st['model'] = ai.ask_json(system, user, temperature=0.7, timeout=wait, max_tokens=12000, effort='medium')
        keep()
    checked, soft = True, []
    while True:
        plan, soft, hard = fix_and_check(st['plan'], m)
        if st['facts_for'] != st['round']:
            st['facts'] = unsupported(plan, m) if not hard and st['round'] < 2 else []
            st['facts_for'], st['plan'] = st['round'], plan
            keep()
        facts = st['facts']
        checked = facts is not None
        soft += facts or []
        log('director round %d (%s): %d words, %d lines, %d hunts | fact check: %s | to fix: %s' % (st['round'] + 1, st['model'], plan.get('words', 0), len(plan.get('lines', [])), len(plan.get('hunts', [])),
            ('%d problems' % len(facts) if facts else 'clean') if checked else 'NOT RUN', '; '.join(soft + hard) or 'nothing'))
        if not soft or st['round'] >= 2:
            break
        st['fix_tries'] = st.get('fix_tries', 0) + 1                # counted across runs: when no model answers the repair, the last plan stands
        if st['fix_tries'] > 2:
            log('director: the repair got no answer twice; keeping the last plan (what is left open goes in the report)')
            break
        if ck:
            json.dump(st, open(ck, 'w', encoding='utf-8'), ensure_ascii=False)
        fix ='Your plan:\n%s\n\nA machine checked it. Fix exactly these points and return the WHOLE corrected JSON plan, same format:\n- %s' % (json.dumps(plan, ensure_ascii=False), '\n- '.join(soft + hard))
        try:
            st['plan'], st['model'] = ai.ask_json(system, user + '\n\n' + fix, temperature=0.4, timeout=wait, max_tokens=12000)
        except ai.AIError as e:
            log('director: the fix round got no answer (%s); keeping the last plan' % str(e)[:100])
            break
        st['round'] += 1
        keep()
    plan, soft2, hard = fix_and_check(st['plan'], m)
    lo = CFG['length']['words_min']
    if plan.get('words', 0) < lo - 25:
        hard.append('the script stayed at %d words (under %d): too short for a video over a minute' % (plan['words'], lo - 25))
    if hard:
        raise SystemExit('PLAN REFUSED: ' + '; '.join(hard))
    plan.update({'url': m['url'], 'title': m['title'], 'model': st['model'], 'assets': assets_of(m), 'left_open': soft2 + (st['facts'] or []) if st['round'] >= 2 else soft2, 'fact_checked': checked})
    return add_site_line(plan)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    work = sys.argv[1]
    mat = json.load(open(os.path.join(work, 'material.json'), encoding='utf-8'))
    pl = direct(mat, lambda *a: print(*a, flush=True), work, float(sys.argv[2]) if len(sys.argv) > 2 else None)
    json.dump(pl, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    for ln in pl['lines']:
        print('%-8s [%s %s] %s\n         %s' % (ln['id'], ln['show']['asset'], ln['show']['mode'], ln['text'], ' | '.join('%s:%s' % (o['k'], o.get('t') or o.get('name') or o.get('post') or len(o.get('rows', o.get('items', [])))) for o in ln['overlays'])))
    print('vote:', json.dumps(pl.get('vote'), ensure_ascii=False), '| hunts:', json.dumps(pl.get('hunts'), ensure_ascii=False), '| stock:', pl.get('stock'), '| sensitive:', pl['sensitive'])
