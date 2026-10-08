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


# ------------------------------------------------------------------------------------------------ the slang format
PALETTE = ('pink', 'yellow', 'green', 'blue', 'orange', 'purple', 'brown', 'red', 'teal')
SLANG_KEYS = ('word', 'theme', 'quiz', 'meaning', 'forms', 'round', 'origin', 'quote', 'final', 'post')
SLANG_SYSTEM = """You are the director of GenZHype's TikTok videos about ONE slang word (vertical, 61 to 75 seconds, one male voice-over, motion graphics only, no footage). The video is a GAME the viewer plays, never a dictionary read aloud. You write ONE plan as strict JSON; a machine builds the video from it with nobody checking.

THE SEVEN PARTS, in this order
1. THE TEST. Three short text messages on screen (A, B, C). Exactly ONE of them uses the word the right way (or is the thing the word names). The viewer picks before the reveal. The machine itself adds "Locked in?", a 3-2-1 and "It's B."
2. THE MEANING. One plain sentence a 14-year-old gets at once, then a second sentence with a picture in words ("Think of...").
3. THE FORMS. The other shapes of the word (the person who does it, the act, a spelling) in one or two short sentences.
4. QUICK ROUND. FIVE everyday situations, one line each (round1 to round5); after each one the verdict is spoken: it IS the word, or it is NOT. At least one of each, two of each is best.
5. THE ORIGIN. Three dated steps if the material has them (two at least), one sentence each, only from the material.
6. A REAL QUOTE or example from the material, if it has a good one (else leave "quote" null and write no "quote" line).
7. FINAL TEST. One situation, two answers of ONE word each. The line ends with both answers and "Comment one word."
Do NOT write a closing line about the website; the machine adds it.

THE VOICE (study the example: this exact rhythm)
- Short sentences. Talk TO the viewer ("you", "your friend"). Jokes are welcome, explaining a joke is not. No "the word X is", no "in various contexts", no "considered", no "often used".
- %(wmin)d to %(wmax)d spoken words in total, counting the machine's own 4 words in part 1. Count them.
- Numbers are written as spoken words in "text" ("the nineteen twenties"), with a "caps" entry so the caption shows digits: {"say":"nineteen twenties","show":"1920s"}.
- TRUTH: the meaning, the forms, the origin and the quote come only from the material. The test texts and the situations are your own everyday examples: they must fit the meaning the material gives. Never guess a person's gender.

EXAMPLE (the word "glaze"; study the lines, then write your own for the new word):
hook: One of these three texts is glazing. Pick one, before I tell you.
mean: To glaze someone is to praise them way more than they've earned. Think of a donut. A shiny, sugary coat, on top of something plain.
forms: Do it a lot, and you're a glazer. And the act? Glazing.
round1: Quick round. Calling your teacher's class the best ever, for a grade: glaze.
round2: Hyping your friend's blurry selfie: glaze.
round3: Your mom saying she's proud of you: not glaze. That's just your mom.
round4: Telling the ref he's the best you've ever seen, right after he gives you the call: glaze.
round5: Thanking the bus driver: not glaze. That's manners.
origin: It showed up online in the early twenty-twenties. A Twitch star took it to the Grammys red carpet. And by twenty twenty-four, a teacher was explaining it on morning TV.
quote: His example? Yo, bro, stop glazing. It's not that big of a deal. So no. It's not a compliment.
final: Final test. He scores twelve, his team loses, and you still post: greatest ever. Glaze, or facts? Comment one word.

OUTPUT: strict JSON, nothing around it:
{"word":"the word","theme":{"color":"one of pink, yellow, green, blue, orange, purple, brown, red, teal that fits the word","emoji":"ONE emoji that fits the word"},
 "quiz":{"question":"WHICH TEXT IS ...? (max 24 characters, capitals)","texts":[{"t":"a text message, max 70 characters","right":false},{"t":"","right":true},{"t":"","right":false}]},
 "meaning":{"pos":"verb, noun, adjective or phrase","text":"the meaning as shown, max 100 characters","source":"where the material takes it from, max 24 characters, capitals"},
 "forms":[{"t":"THE FORM, max 14 characters","tag":"what it is, max 22 characters","on":"one word of the forms line"}],
 "round":{"meter":"a name for the meter, max 18 characters, e.g. GLAZE-O-METER","yes":"the verdict when it is, max 12 characters","no":"the verdict when it is not, max 14 characters",
          "items":[{"t":"the situation as shown, max 80 characters","is":true,"pct":93},{"t":"","is":true,"pct":80},{"t":"","is":false,"pct":12},{"t":"","is":true,"pct":88},{"t":"","is":false,"pct":8}]},
 "origin":[{"when":"the date in DIGITS as shown on screen, e.g. 1920s or EARLY 2020s (max 14 characters)","t":"what happened, max 56 characters","on":"one word of the origin line"}],
 "quote":{"t":"real words from the material, max 90 characters","who":"WHO · WHERE"},
 "final":{"setup":"the situation as shown, max 70 characters","a":{"word":"ONE WORD","sub":"that side in 3 words"},"b":{"word":"ONE WORD","sub":"that side in 3 words"}},
 "lines":[{"id":"hook","text":"","caps":[]},{"id":"mean","text":""},{"id":"forms","text":""},{"id":"round1","text":""},{"id":"round2","text":""},{"id":"round3","text":""},{"id":"round4","text":""},{"id":"round5","text":""},{"id":"origin","text":""},{"id":"quote","text":""},{"id":"final","text":""}],
 "post":{"caption":"max 150 characters, ends with the final choice","hashtags":["8 to 11 lowercase tags without #"],"pinned":"the final choice again, then: the full story of the word on genzhype.com (link in bio)"}}
Each round line says its situation and ends with its verdict (the "yes" or the "no" words of "round"). "round1" starts with "Quick round." The hook says "Pick one"."""


DISPUTED = re.compile(r'\b(pejorativ\w*|derogator\w*|politic\w*|culture war\w*|controvers\w*|contested|polariz\w*|divisive|slur)\b', re.I)
DISPUTED_RULE = (' THIS WORD IS DISPUTED: the material says people use it in opposite ways, or as an insult. So the video sorts USES of the word and never judges people or causes. '
                 'Every test text and every quick-round situation is a SENTENCE SOMEONE SAYS that contains the word, and the verdict names which sense that sentence uses: set "yes" and "no" to the two senses '
                 '(for example ORIGINAL and INSULT). Never put the word, or a verdict, on a real cause, movement, group, belief, religion or party. Give both senses evenly. '
                 'The final test is also about a sentence, and its two answers are the two senses.')


def is_disputed(m):
    hits = {h.lower()[:6] for h in DISPUTED.findall(m.get('page_text', ''))}
    return len(hits) >= 2 or any(h.startswith('politi') for h in hits)


def prompt_slang(m):
    slist = '\n\n'.join('SOURCE %s: "%s"\n%s' % (s['publisher'], s['title'], s['excerpt'][:2200]) for s in m.get('sources', []) if 'fonts.' not in s['publisher']) or '(none fetched)'
    user = 'THE WORD PAGE\nTitle: %s\nPage: %s\n\nOUR PAGE (the meaning, the forms, the origin and the examples to use):\n%s\n\nWHAT THE SOURCES SAY:\n%s\n\nWrite the plan.' % (m['title'], m['url'], m['page_text'][:6500], slist)
    return SLANG_SYSTEM % {'wmin': CFG['length']['words_min'], 'wmax': CFG['length']['words_max']} + ('\n\n' + DISPUTED_RULE.strip() if is_disputed(m) else ''), user


def slang_for_repair(p):
    return dict({k: p.get(k) for k in SLANG_KEYS}, lines=p.get('raw_lines') or p.get('lines'))


def check_slang(p, m):
    """The slang plan made buildable: every text cut to its place, the machine's own lines added, and what the AI must fix."""
    soft, hard, cor = [], [], corpus(m)
    cut = lambda v, n: re.sub(r'\s+', ' ', str(v or '')).strip()[:n]
    part = lambda k: p.get(k) if isinstance(p.get(k), dict) else {}
    word = cut(p.get('word'), 24) or cut(re.sub(r"(?i)what does|mean\??|['‘’\"]", '', m.get('title', '')), 24)
    theme = part('theme')
    color = theme.get('color') if theme.get('color') in PALETTE else PALETTE[len(word) % len(PALETTE)]
    quiz = part('quiz')
    texts = [{'t': cut(x.get('t'), 84), 'right': bool(x.get('right'))} for x in (quiz.get('texts') or []) if isinstance(x, dict) and cut(x.get('t'), 84)][:3]
    if len(texts) != 3 or sum(x['right'] for x in texts) != 1:
        soft.append('the test needs exactly 3 texts with exactly ONE marked "right": true')
        texts = (texts + [{'t': '...', 'right': False}] * 3)[:3]
        if sum(x['right'] for x in texts) != 1:
            for i, x in enumerate(texts):
                x['right'] = i == 1
    meaning = part('meaning')
    mean = {'pos': cut(meaning.get('pos'), 20).lower(), 'text': cut(meaning.get('text'), 110), 'source': cut(meaning.get('source'), 26).upper()}
    if len(mean['text']) < 12:
        soft.append('"meaning.text" is missing')
    rnd = part('round')
    items = []
    for x in (rnd.get('items') or [])[:6]:
        if isinstance(x, dict) and cut(x.get('t'), 90):
            is_ = bool(x.get('is'))
            try:
                pct = int(min(97, max(3, float(x.get('pct')))))
            except (TypeError, ValueError):
                pct = 90 if is_ else 12
            items.append({'t': cut(x.get('t'), 90), 'is': is_, 'pct': pct if (pct >= 50) == is_ else (90 if is_ else 12)})
    if len(items) < 5:
        soft.append('the quick round needs five situations (round1 to round5, one line each); it has %d' % len(items))
    if len(items) >= 2 and len({i['is'] for i in items}) < 2:
        soft.append('the quick round needs at least one situation that IS the word and one that is NOT')
    yes, no = cut(rnd.get('yes'), 14).upper() or word.upper(), cut(rnd.get('no'), 16).upper() or 'NOT ' + word.upper()
    final = part('final')
    fa, fb = (final.get('a') if isinstance(final.get('a'), dict) else {}), (final.get('b') if isinstance(final.get('b'), dict) else {})
    a_word, b_word = cut(fa.get('word'), 12).upper(), cut(fb.get('word'), 12).upper()
    if not a_word or not b_word or {norm(a_word), norm(b_word)} & {'yes', 'no', 'maybe'} or norm(a_word) == norm(b_word):
        soft.append('the final test needs two different answers of one word each (never yes / no)')
    q = part('quote')
    quote = {'t': cut(q.get('t'), 110), 'who': cut(q.get('who'), 40)} if len(norm(cut(q.get('t'), 110))) >= 10 and cut(q.get('who'), 40) and norm(cut(q.get('t'), 110))[:40] in cor else None

    raw = p.get('raw_lines') or p.get('lines') or []
    by = {}
    for l in raw:
        if isinstance(l, dict) and cut(l.get('text'), 500):
            by.setdefault(re.sub(r'[^a-z0-9]', '', str(l.get('id', '')).lower()), l)
    need = ['hook', 'mean', 'forms'] + ['round%d' % (i + 1) for i in range(len(items))] + ['origin', 'final']
    missing = [i for i in need if i not in by]
    if missing:
        soft.append('these lines are missing: %s' % ', '.join(missing))
    order = ['hook', 'lock', 'rev', 'mean', 'forms'] + ['round%d' % (i + 1) for i in range(len(items))] + ['origin'] + (['quote'] if quote and 'quote' in by else []) + ['final']
    letter = 'ABC'[[x['right'] for x in texts].index(True)]
    notes = {'hook': ['EXAMPLE texts on screen: ' + ' / '.join(x['t'] for x in texts)], 'mean': [mean['text'], mean['source']], 'final': ['EXAMPLE: ' + cut(final.get('setup'), 80)],
             'quote': [quote['t'] + ' (' + quote['who'] + ')'] if quote else []}
    for i, it in enumerate(items):
        notes['round%d' % (i + 1)] = ['EXAMPLE: %s -> %s' % (it['t'], yes if it['is'] else no)]
    lines, total = [], 0
    for lid in order:
        if lid == 'lock':
            l = {'id': 'lock', 'text': 'Locked in?', 'rate': 6, 'pause': 1.6, 'caps': []}      # the 3-2-1 runs in this silence
        elif lid == 'rev':
            l = {'id': 'rev', 'text': "It's %s." % letter, 'rate': 4, 'pause': 0.7, 'caps': []}
        elif lid in by:
            t = cut(by[lid].get('text'), 500)
            l = {'id': lid, 'text': t, 'caps': [{'say': str(c['say']).strip(), 'show': str(c['show']).strip()[:14]} for c in (by[lid].get('caps') or [])
                                               if isinstance(c, dict) and c.get('say') and c.get('show') and norm(c['say']) in norm(t)]}
            l['pause'] = 0.2 if lid == 'hook' else 1.1 if lid == 'final' else 0.55 if lid.startswith('round') else 0.45      # a game needs beats: time to read, a breath between scenes
            low = t.lower()
            for b in BANNED + ['the word', 'in various contexts', 'is considered', 'often used']:
                if b in low:
                    soft.append('line "%s" uses "%s": talk to the viewer, say the thing itself' % (lid, b))
            if re.search(r'\d', t):
                soft.append('line "%s" has digits in the spoken text ("%s"): write numbers as spoken words and put the digits in "caps"' % (lid, re.search(r'\S*\d\S*', t).group(0)))
            if words(t) > 40:
                soft.append('line "%s" has %d words: cut it to 34' % (lid, words(t)))
            if HEAVY.search(t) and not SAYS.search(t) and '"' not in t:
                soft.append('line "%s" states "%s" as a fact: say who says it' % (lid, HEAVY.search(t).group(0)))
        else:
            continue
        l.update(show={'asset': 'none', 'mode': 'under'}, overlays=[{'k': 'note', 't': n} for n in notes.get(lid, []) if n])
        total += words(l['text'])
        lines.append(l)
    if 'hook' in by and 'pick' not in norm(by['hook'].get('text', '')):
        soft.append('the hook must tell the viewer to pick one of the three texts')
    for i, it in enumerate(items):
        lid = 'round%d' % (i + 1)
        if lid in by and norm(yes if it['is'] else no).split(' ')[-1] not in norm(by[lid].get('text', '')):
            soft.append('line "%s" must end with its verdict: "%s"' % (lid, (yes if it['is'] else no).lower()))
    if 'final' in by and a_word and b_word:
        ft = norm(by['final'].get('text', ''))
        if norm(a_word) not in ft or norm(b_word) not in ft or 'comment' not in ft:
            soft.append('the final line must say both answers ("%s", "%s") and "Comment one word."' % (a_word.lower(), b_word.lower()))
    lo, hi = CFG['length']['words_min'], CFG['length']['words_max']
    if total < lo:
        soft.append('only %d spoken words: write %d to %d (a longer quick round or one more origin step, never padding)' % (total, lo, hi))
    if total > hi + 12:
        soft.append('%d spoken words: cut to %d at most' % (total, hi))
    tok = lambda lid: tokens(by[lid].get('text', '')) if lid in by else []
    on = lambda v, lid: (tokens(str(v or ''))[:1] or [''])[0] if (tokens(str(v or ''))[:1] or [''])[0] in tok(lid) else ''
    forms = [{'t': cut(x.get('t'), 16).upper(), 'tag': cut(x.get('tag'), 24).upper(), 'on': on(x.get('on'), 'forms')} for x in (p.get('forms') or []) if isinstance(x, dict) and cut(x.get('t'), 16)][:3]
    origin = [{'when': cut(x.get('when'), 16).upper() if isinstance(x.get('when'), str) else '', 't': cut(x.get('t'), 60), 'on': on(x.get('on'), 'origin')} for x in (p.get('origin') or []) if isinstance(x, dict) and isinstance(x.get('t'), str) and cut(x.get('t'), 60)][:3]
    if not forms:
        soft.append('"forms" needs 1 to 3 forms of the word')
    if len(origin) < 2:
        soft.append('"origin" needs 2 or 3 dated steps from the material')
    for o in origin:
        if not re.search(r'\d', o['when']):
            o['when'] = ''                                       # shown without its date rather than with a spelled-out one
            soft.append('origin "when" is shown on screen: write the date in digits ("1920s", "2010s", "NOV 2024"), not "%s"' % o['when'].lower()[:24])
    for i, it in enumerate(items):
        lid, last = 'round%d' % (i + 1), norm(yes).split(' ')[-1]
        if lid in by and last and norm(by[lid].get('text', '')).split(' ').count(last) > 2:
            soft.append('line "%s" holds several situations: ONE situation and its verdict per round line (three lines, three situations)' % lid)
    disputed = is_disputed(m)
    if disputed and word:
        loose = [x['t'] for x in texts + items if norm(word) not in norm(x['t'])]
        if loose:
            soft.append('this word is disputed: every test text and quick-round situation must be a sentence someone SAYS that contains "%s", sorted by which sense it uses; these do not: %s' % (word.lower(), ' | '.join(loose)[:200]))
    p.update({'format': 'slang', 'raw_lines': raw, 'lines': lines, 'words': total, 'hunts': [], 'incomplete': bool(missing),
              'slang': {'word': word, 'disputed': disputed, 'theme': {'color': color, 'emoji': cut(theme.get('emoji'), 8) or '💬'}, 'quiz': {'question': cut(quiz.get('question'), 26).upper() or 'WHICH TEXT IS RIGHT?', 'texts': texts},
                        'meaning': mean, 'forms': forms, 'round': {'meter': cut(rnd.get('meter'), 20).upper() or word.upper() + '-O-METER', 'yes': yes, 'no': no, 'items': items},
                        'origin': origin, 'quote': quote, 'final': {'setup': cut(final.get('setup'), 80), 'a': {'word': a_word, 'sub': cut(fa.get('sub'), 26)}, 'b': {'word': b_word, 'sub': cut(fb.get('sub'), 26)}}}})
    flat = m.get('title', '') + ' ' + m.get('summary', '')
    p['sensitive'] = {'flag': bool(SENSITIVE.search(flat)), 'why': (SENSITIVE.search(flat).group(0) if SENSITIVE.search(flat) else '')}
    return p, soft, hard


def slang_top_up(p, m, log):
    """A short slang script gets MORE quick-round situations (up to six in all): lines are only added, nothing is rewritten.
    Returns the plan with the additions (to be checked again), or None when nothing could be added."""
    S, lo = p['slang'], CFG['length']['words_min']
    items = S['round']['items']
    want = min(6 - len(items), max(1, -(-(lo - p.get('words', 0)) // 11)))
    if want <= 0:
        return None
    user = ('THE WORD: %s\nIT MEANS: %s\nThe quick round so far (what is shown -> the verdict):\n%s\n\nWrite %d MORE quick-round situations in the same style: everyday, concrete, different from these. '
            'Verdict words: "%s" when it is, "%s" when it is not.%s\n'
            'JSON: {"items":[{"t":"the situation as shown on screen, max 80 characters","is":true,"line":"the spoken line: the situation, then the verdict words at the very end"}]}'
            % (S['word'], S['meaning']['text'], '\n'.join('- %s -> %s' % (i['t'], S['round']['yes'] if i['is'] else S['round']['no']) for i in items), want,
               S['round']['yes'].lower(), S['round']['no'].lower(), DISPUTED_RULE if S.get('disputed') else ''))
    j, _ = ai.ask_json('You add lines to a short video script about one slang word. Strict JSON only.', user, temperature=0.6, timeout=ai.CONFIG['ai'].get('timeout', 100), max_tokens=1500)
    p['round'] = dict(p.get('round') if isinstance(p.get('round'), dict) else {}, items=[dict(i) for i in items])
    lines, added = list(p.get('raw_lines') or []), 0
    for x in (j.get('items') or [])[:want]:
        if isinstance(x, dict) and str(x.get('t', '')).strip() and str(x.get('line', '')).strip():
            p['round']['items'].append({'t': str(x['t']), 'is': bool(x.get('is')), 'pct': 90 if x.get('is') else 12})
            lines.append({'id': 'round%d' % len(p['round']['items']), 'text': str(x['line'])})
            added += 1
    p['raw_lines'] = lines
    log('director: the script is short; asked for %d more quick-round situations, got %d' % (want, added))
    return p if added else None


def unsupported(plan, m):
    """A second, adversarial reading: every spoken sentence and on-screen text against the material. Returns the reasons to fix."""
    def screen(o):
        return ' '.join([str(o.get('t') or o.get('name') or '')] + [r.get('t', '') for r in o.get('rows', [])] + [x.get('label', '') + ' ' + x.get('big', '') for x in o.get('items', [])]).strip()
    script = '\n'.join('%d. %s  [on screen: %s]' % (i + 1, l['text'], ' / '.join(screen(o) for o in l['overlays'])) for i, l in enumerate(plan['lines']))
    mat = 'OUR PAGE:\n%s\n\nPOSTS:\n%s\n\nOUTLETS:\n%s' % (m['page_text'][:6500], '\n'.join('%s: %s' % (p['handle'], p['text'][:500]) for p in m.get('posts', [])), '\n\n'.join(s['excerpt'][:2600] for s in m.get('sources', [])))
    sys_ = ('You are a strict fact checker. You get a short video script and the ONLY material it may use. List every statement in the script (spoken or on screen) that the material does not support: '
            'invented facts, numbers or names that differ, superlatives and predictions the material does not make ("the best in the world", "they will lose"), a claim about wrongdoing stated without who says it, '
            'a guessed gender. A fair summary of what the material says is supported. Questions and the vote are not claims. Lines marked EXAMPLE are made-up everyday illustrations of how the word is used: judge only whether they fit the meaning the material gives. Strict JSON only: {"problems":[{"line":1,"text":"the words","why":"short"}]} (an empty list if all is supported).')
    try:
        j, _ = ai.ask_json(sys_, 'MATERIAL\n%s\n\nSCRIPT\n%s' % (mat, script), temperature=0.1, timeout=ai.CONFIG['ai'].get('timeout', 100), max_tokens=6000)
    except ai.AIError:
        return None                                           # no checker answered: said in the report, the plan is kept
    return ['line %s says "%s": not in the material (%s); say only what the material says, or cut it' % (p.get('line'), str(p.get('text', ''))[:70], str(p.get('why', ''))[:80])
            for p in j.get('problems', []) if isinstance(p, dict)][:8]


def direct(m, log=print, work=None, budget=None):
    """The plan: one draft, then up to two corrections (code checks + a fact check on every version). THE BEST VERSION IS
    KEPT: a correction that comes back worse (too short for a minute, more unsupported statements, more faults, fewer
    words) is thrown away, and the next correction starts again from the best one. With a work folder the progress is
    kept after every AI answer (plan_progress.json): a stopped run, or a caller with a time limit (budget, in seconds:
    exit code 3 = run again), continues where it was instead of paying for the same answers twice."""
    slang_page = '/slang/' in m['url']                         # a word page is told as a game (the slang format), a story page as a story
    system, user = prompt_slang(m) if slang_page else prompt_for(m)
    check = check_slang if slang_page else fix_and_check
    lo, hi = CFG['length']['words_min'], CFG['length']['words_max']
    t0, wait = time.time(), ai.CONFIG['ai'].get('timeout', 100)
    ck = os.path.join(work, 'plan_progress.json') if work else None
    st = json.load(open(ck, encoding='utf-8')) if ck and os.path.isfile(ck) else {'round': 0, 'plan': None, 'model': '', 'facts': None, 'facts_for': -1, 'best': None}
    copy = lambda x: json.loads(json.dumps(x))

    def keep():
        if ck:
            json.dump(st, open(ck, 'w', encoding='utf-8'), ensure_ascii=False)
        if budget and time.time() - t0 > budget:
            log('director: stopped at the time budget in round %d; run again' % (st['round'] + 1))
            raise SystemExit(3)

    def rank(plan, soft, hard, facts):
        """Lower is better: broken, then too short to reach a minute, then unsupported statements, then how far under the
        wanted length (in steps of ten words: length weighs more than a small fault), then the other faults, then words."""
        w = plan.get('words', 0)
        return [len(hard) + (1 if plan.get('incomplete') else 0), 0 if w >= lo - 25 else 1, len(facts or []), -(-max(0, lo - w) // 10), len(soft), -min(w, hi)]

    if st['plan'] is None:
        st['plan'], st['model'] = ai.ask_json(system, user, temperature=0.7, timeout=wait, max_tokens=12000, effort='medium')
        keep()
    while True:
        plan, soft, hard = check(st['plan'], m)
        if st['facts_for'] != st['round']:                        # every version is fact-checked, the last one included
            st['facts'] = unsupported(plan, m) if not hard else []
            st['facts_for'], st['plan'] = st['round'], plan
            keep()
        facts, r = st['facts'], rank(plan, soft, hard, st['facts'])
        better = st.get('best') is None or r < st['best']['rank']
        log('director round %d (%s): %d words, %d lines, %d hunts | fact check: %s | faults: %s | %s' % (st['round'] + 1, st['model'], plan.get('words', 0), len(plan.get('lines', [])),
            len(plan.get('hunts', [])), 'NOT RUN' if facts is None else '%d problems' % len(facts) if facts else 'clean', '; '.join(soft + hard) or 'none',
            'KEPT as the best so far' if better else 'WORSE than the best so far (%d words): thrown away' % st['best']['plan'].get('words', 0)))
        if better:
            st['best'] = {'plan': copy(plan), 'model': st['model'], 'rank': r, 'facts': facts}
        base, bsoft, bhard = check(copy(st['best']['plan']), m)       # the next correction always starts from the best version
        todo = bsoft + bhard + (st['best']['facts'] or [])
        if not todo or st['round'] >= 2:
            break
        st['fix_tries'] = st.get('fix_tries', 0) + 1                # counted across runs: when no model answers the correction, the best version stands
        if st['fix_tries'] > 2:
            log('director: the correction got no answer twice; the best version stands (what is left open goes in the report)')
            break
        if ck:
            json.dump(st, open(ck, 'w', encoding='utf-8'), ensure_ascii=False)
        fix = ('Your plan:\n%s\n\nA machine checked it. Fix exactly these points and return the WHOLE corrected JSON plan, same format:\n- %s\n\n'
               'Change ONLY what is listed above. Keep every other line word for word. The plan has %d spoken words now: the corrected plan must have %d to %d, never fewer than now.'
               % (json.dumps(slang_for_repair(base) if slang_page else base, ensure_ascii=False), '\n- '.join(todo), base.get('words', 0), max(lo, base.get('words', 0)), hi))
        try:
            st['plan'], st['model'] = ai.ask_json(system, user + '\n\n' + fix, temperature=0.4, timeout=wait, max_tokens=12000)
        except ai.AIError as e:
            log('director: the correction got no answer (%s); the best version stands' % str(e)[:100])
            break
        st['round'] += 1
        keep()
    if slang_page and not st.get('topped') and st['best']['plan'].get('words', 0) < lo and not st['best']['plan'].get('incomplete'):
        st['topped'] = True                                         # a short script gets more quick-round lines: added, never rewritten
        try:
            grown = slang_top_up(copy(st['best']['plan']), m, log)
        except ai.AIError as e:
            grown = None
            log('director: the extra lines got no answer (%s)' % str(e)[:80])
        if grown:
            g, gsoft, ghard = check(grown, m)
            gfacts = unsupported(g, m) if not ghard else []
            gr = rank(g, gsoft, ghard, gfacts)
            log('director: with the extra lines: %d words | fact check: %s | %s' % (g.get('words', 0), 'NOT RUN' if gfacts is None else '%d problems' % len(gfacts) if gfacts else 'clean',
                'KEPT' if gr < st['best']['rank'] else 'not better: thrown away'))
            if gr < st['best']['rank']:
                st['best'] = {'plan': copy(g), 'model': st['best']['model'], 'rank': gr, 'facts': gfacts}
        keep()
    plan, soft2, hard = check(copy(st['best']['plan']), m)
    facts = st['best']['facts']
    if plan.get('incomplete'):
        hard.append('the plan still lacks lines the format needs')
    if plan.get('words', 0) < lo - 25:
        hard.append('the best script has %d words (under %d): too short for a video over a minute' % (plan['words'], lo - 25))
    if hard:
        raise SystemExit('PLAN REFUSED: ' + '; '.join(hard))
    log('director: the plan used is the one by %s, %d words, %s' % (st['best']['model'], plan.get('words', 0),
        'fact check not run' if facts is None else '%d unsupported statements left' % len(facts) if facts else 'fact check clean'))
    plan.update({'url': m['url'], 'title': m['title'], 'model': st['best']['model'], 'assets': {} if slang_page else assets_of(m), 'left_open': soft2 + (facts or []),
                 'fact_checked': facts is not None, 'unsupported_left': len(facts or [])})
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
