"""THE DIRECTOR: reads one story's material and writes the plan of its video (plan.json), with nobody in the loop.
  1. three small jobs instead of one big one: a WRITER writes five openings and the script (words only), a second
     reader picks the opening that would stop a thumb, a PICTURE EDITOR says what is on screen for each line (a small
     fixed vocabulary of graphics). A meme's own pictures are fetched and looked at first: the words fit the pictures;
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


# how an article sounds; a voice-over that uses these is sent back
ARTICLE = re.compile(r"(,\s*explained\b|\b(?:phenomenon|showcas\w*|garner\w*|amid|netizens|widespread|refers to|various|numerous|moreover|furthermore|additionally|notably|utiliz\w*|"
                     r"continues to|sparks? (?:a |fierce |heated )?debate|sparking|social media users|users|remains to be seen|taken the internet|in the world of)\b)", re.I)


def sentences(t):
    return [x for x in re.split(r'(?<=[.!?…])["”’\']?\s+', (t or '').strip()) if re.search(r'\w', x)]


def is_meme(m):
    return '/meme/' in m.get('url', '')


def norm(s):
    return re.sub(r'[^a-z0-9€$%]+', ' ', (s or '').lower().replace('’', "'").replace("'", '')).strip()


def tokens(text):
    return [w.strip('.,?!:;"“”()').lower().replace('’', "'") for w in text.split()]


def words(text):
    return len(text.split())


def corpus(m):
    return norm(' '.join([m.get('page_text', ''), m.get('summary', '')] + [p['text'] for p in m.get('posts', [])] + [s.get('excerpt', '') + ' ' + s.get('title', '') for s in m.get('sources', [])] + [e.get('title', '') for e in m.get('examples') or []]))


def assets_of(m):
    """What can be put on screen before any search: the videos and pictures of the story's own posts."""
    out = {}
    for i, p in enumerate(m.get('posts', [])):
        for md in p['media']:
            if md['type'] == 'video' and md.get('video') and 'clip%d' % i not in out:
                out['clip%d' % i] = {'kind': 'clip', 'post': i, 'url': md['video'], 'seconds': md.get('seconds'), 'credit': 'CLIP · %s / X' % p['handle'].upper(), 'about': p['text'][:240], 'by': p['handle'], 'page': p['url']}
            elif md['type'] == 'photo' and md.get('image') and 'photo%d' % i not in out:
                out['photo%d' % i] = {'kind': 'photo', 'post': i, 'url': md['image'], 'credit': 'IMAGE · %s / X' % p['handle'].upper(), 'about': p['text'][:240], 'by': p['handle'], 'page': p['url']}
    memes = {k: v for k, v in (m.get('meme_assets') or {}).items() if v.get('usable', True)}      # a meme's own examples: already fetched and looked at (memes.py)
    out.update(memes)
    for i, im in enumerate(m.get('images') or []):             # the page's own pictures
        if m.get('examples') and '/covers/' not in im['url']:  # a meme page's small pictures are copies of its examples, which were fetched and looked at themselves
            continue
        out['page%d' % i] = {'kind': 'photo', 'url': im['url'], 'credit': '', 'about': im.get('alt', ''), 'by': 'the page', 'page': m.get('url', '')}
    return out


HEAD = ('You are the %s of GenZHype\'s TikTok videos (vertical, 61 to 75 seconds, one male voice-over, footage under everything). GenZHype is "the receipts, not the gossip": '
        'creator, gaming and meme stories told with the proof on screen.\nA machine builds the video from your JSON with nobody checking, so follow the format exactly.')

SHAPE = {'story': """HOW THE VIDEO WORKS
- Line 1 is THE OPENING (rules below). It plays on real footage, with its key number or word stamped on screen.
- Line 2 says who or what this is, in one breath. Then the evidence, beat by beat: each beat is one line with its proof (a post, a quote, a number). One line is the turn. One line says what is at stake.
- The last line is a VOTE: one question, two answers of ONE or TWO words each, taken from the story's real conflict (never "yes / no"). The line says "Comment one word." and both answers.
- Do NOT write the closing line that sends to the website; the machine adds it.""",
         'meme': """THIS STORY IS A MEME. The video shows the meme itself from the first second to the last: its real pictures are listed in the material, each with what it shows.
- Line 1 is THE OPENING (rules below): it points at the meme on screen and says the strangest true thing about it.
- Line 2 says what the meme is, in one breath, for someone who has never seen it. Then: where it came from (who made it, when), how people USE it (two or three real uses from the material, one short line each, with the account or the number), how big it got (the biggest number), and what the joke or the argument really is.
- Point at the screen ("Look at the one on the left.", "That face is the whole joke."): a meme video that never points at the meme is a book report.
- The last line is a VOTE: one question, two answers of ONE or TWO words each, taken from the meme's own argument (never "yes / no"). The line says "Comment one word." and both answers.
- Do NOT write the closing line that sends to the website; the machine adds it."""}

VIEWER = """WHO IS WATCHING
A 16-year-old scrolling with the sound on. They give the video two seconds, they have never heard of this story, and they leave the moment a sentence sounds like an article or a school report."""

OPENING = """THE OPENING (line 1) decides everything
- 2 or 3 short sentences, 10 to 30 words in all. The FIRST sentence has 10 words at most. It puts the strangest TRUE thing of the story in front of the viewer: a number, a name, or the thing they are looking at.
- It makes the viewer need the next sentence. It never explains, sums up or announces ("X, explained", "here is why", "a new trend is taking over").
- It never starts with "The", "A new", "In", "On", "Recently", a date or an outlet's name. It never orders the viewer around before showing anything ("Pick...", "Guess...", "Watch...").
- Five ways to build one. These are openings of OTHER stories: take the shape, never the facts.
  number:   "Twenty-two million views. For a game that does not exist."
  contrast: "This team packs arenas with tens of thousands of fans. Now its two star players are owed over three hundred thousand euros."
  picture:  "This ship can cost seven hundred dollars in a video game. One problem. An artist designed it twelve years ago."
  list:     "Skateboarding, in Call of Duty. Minecraft, in Elden Ring. Tarkov, in Skyrim. None of these games exist."
  fight:    "The boss says everyone got paid. The agent says three hundred thousand euros are missing. One of them is wrong." """

VOICE = """THE VOICE (study the examples: this exact rhythm)
- You are telling a friend who has not seen it. Short sentences, one idea each: most have 3 to 9 words, NONE has more than 16. A line is 2 to 4 of them. Fragments are good ("Not bonuses. Wages."). No semicolons, no dashes that join two ideas: a new idea is a new sentence.
- Every line brings ONE new thing, with its name, its number or its quote. One line in the middle is the turn and starts with "But", "Then", "Now", "Same night" or "Meanwhile".
- Never sound like an article: no "explained", "phenomenon", "showcases", "garnered", "sparks debate", "amid", "users", "netizens", "widespread", "refers to", "various", "numerous", "moreover", "furthermore", "notably", "continues to". No filler, no hype words, no questions to "fans", no "official source". Present tense where possible.
- %(wmin)d to %(wmax)d spoken words in total, in 8 to 9 lines of 12 to 32 words. Count them.
- Numbers are written as spoken words ("three hundred thousand euros", "twenty-fourteen"), never in digits.
- Do not hedge every sentence. Say who reports a claim ONCE, where it first appears ("Kotaku reports", "the patch notes say"), then tell it plainly. Never say "end quote".
- Tell the story, not our work: never mention GenZHype's checking, "we found", "unverified", "our page".
- TRUTH: use only facts that are in the material. Anything disputed, or about wrongdoing, is said with who says it ("the agent says", "police say", "reportedly", "according to Kotaku"). A quote must be the source's real words (translated quotes: say "translated"). Never guess a person's gender: use the name or "they"."""

SCREEN = """ON SCREEN, for each line
- "show": what footage is under the line. {"asset":"clip0"} / {"asset":"photo1"} / {"asset":"meme2"} = a clip or picture the story already has (ids listed in the material). {"asset":"hunt1"} = footage you ask for in "hunts". "mode":"sharp" = the footage is the point (the opening, a person talking, the thing itself); "mode":"under" = blurred behind a card.
  A line WITHOUT a card should be "sharp". A clip of a person may only be shown sharp on a line about THAT person (the clip's own post tells you who is in it).
- "hunts": up to %(hunts)d footage requests for things the words describe but the material does not show: the game's gameplay or trailer, the event, the arena, the product. Each: {"id":"hunt1","query":"4 to 7 search words naming the exact thing","must_show":"what the picture must show","game":"only when the footage wanted is a video game: its exact title, nothing else"}. A hunt with "game" gets that game's official trailer (always found, always the right game); use one for every game the story is about. Ask for enough: every line needs footage, a video with one clip is refused. Hunt THINGS (the game, the event, the arena, the product, a trailer), not a named person's face: a search cannot promise who is in the picture. Never hunt a private person's home, a victim, a mugshot.
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
- Line 1 always gets a "stamp": the key number or word of the opening.
- Show the proof: every post that matters appears once as a "receipt"; the strongest quote appears as a "quote".
- "caps": every number that is spoken in words in that line, with the digits the caption shows: {"say":"twenty-two million","show":"22 million"}."""

SCREEN_MEME = """
- THIS IS A MEME: every line shows one of the meme's own pictures ("meme0", "meme1"...). Choose the one whose description fits the words of that line, and change picture from line to line. Use at most TWO cards (rows / quote / blocks) in the whole video, so the meme stays visible; stamps and chips are fine. Hunts: only the meme's ORIGINAL video by its exact name, or the game, show or film it comes from."""

OUT_WRITE = """OUTPUT: strict JSON, nothing around it. Write the five openings FIRST, then pick one, then write the script that follows it:
{"angle":"the conflict in one sentence",
 "openings":[{"how":"number","say":["",""]},{"how":"contrast","say":["",""]},{"how":"picture","say":["",""]},{"how":"list","say":["","",""]},{"how":"fight","say":["",""]}],
 "pick":1,
 "lines":[{"id":"hook","say":["the opening you picked,","sentence by sentence"]},{"id":"who","say":["One short sentence.","Then another."]},{"id":"...","say":["",""]},{"id":"vote","say":["The question.","Comment one word.","This, or that."]}],
 "vote":{"question":"max 22 characters","a":{"word":"ONE WORD","sub":"that side in 3 words"},"b":{"word":"ONE WORD","sub":"that side in 3 words"}},
 "people":[{"name":"","role":""}]}
Every "say" is a list of 2 or 3 SENTENCES (the opening may have 4): ONE sentence per string, each of 3 to 12 words (only a real quote may be longer). A string never holds two ideas.
ALL the lines together: %(wmin)d to %(wmax)d words, which is about 19 words a line. A script that runs longer gets sentences removed by a machine, so choose what matters yourself.
"pick" is the number (1 to 5) of the strongest opening. The first line's id is "hook", the last line's id is "vote". You write only what is SPOKEN: no pictures, no graphics."""

OUT_STAGE = """OUTPUT: strict JSON, nothing around it. One entry per line of the script, same ids, same order, WITHOUT the spoken text:
{"lines":[{"id":"hook","show":{"asset":"clip0","mode":"sharp"},"overlays":[{"k":"stamp","t":"...","on":"word"}],"caps":[{"say":"...","show":"..."}]}],
 "hunts":[{"id":"hunt1","query":"","must_show":"","game":""}],"stock":"3 words for neutral background footage of this story's world",
 "post":{"caption":"max 150 characters, ends with the vote","hashtags":["8 to 11 lowercase tags without #"],"pinned":"the vote again, then: receipts on genzhype.com (link in bio)"}}"""

OUT_FULL = """OUTPUT: strict JSON, nothing around it:
{"angle":"the conflict in one sentence","lines":[{"id":"hook","text":"...","show":{"asset":"clip0","mode":"sharp"},"overlays":[{"k":"stamp","t":"...","on":"word"}],"caps":[{"say":"...","show":"..."}]}],
 "vote":{"question":"max 22 characters","a":{"word":"ONE WORD","sub":"that side in 3 words"},"b":{"word":"ONE WORD","sub":"that side in 3 words"}},
 "people":[{"name":"","role":""}],"hunts":[{"id":"hunt1","query":"","must_show":""}],"stock":"3 words for neutral background footage of this story's world",
 "post":{"caption":"max 150 characters, ends with the vote","hashtags":["8 to 11 lowercase tags without #"],"pinned":"the vote again, then: receipts on genzhype.com (link in bio)"}}
The last line's id must be "vote"."""

STAGE_JOB = 'You get a FINISHED voice-over script. You decide what is on screen for each of its lines. You never change, add or drop a spoken word.'
JUDGE = 'You are a 16-year-old scrolling TikTok with the sound on. You get several openings written for the SAME video. You pick the one that would make you stop and watch. Strict JSON only.'


def system_for(job, m):
    """The instructions of one job: the 'writer' (words only), the 'picture editor' (what is on screen), or the
    'director' (both at once: used when a finished plan is sent back to be corrected)."""
    kind = 'meme' if is_meme(m) and any(v.get('whole') for v in assets_of(m).values()) else 'story'
    screen = SCREEN + (SCREEN_MEME if kind == 'meme' else '')
    parts = {'writer': [SHAPE[kind], VIEWER, OPENING, VOICE, OUT_WRITE], 'picture editor': [STAGE_JOB, screen, OUT_STAGE], 'director': [SHAPE[kind], VIEWER, OPENING, VOICE, screen, OUT_FULL]}[job]
    return (HEAD % job) + '\n\n' + '\n\n'.join(parts) % {'wmin': CFG['length']['words_min'], 'wmax': CFG['length']['words_max'], 'hunts': 2 if kind == 'meme' else CFG['footage']['max_hunts']}


def listing(m):
    """The material's lists as the prompts print them: what can be shown, the posts, the outlets."""
    def one(k, v):
        if v.get('whole'):                                     # one of the meme's own examples, with what the picture model saw in it
            what = {'gif': 'a moving GIF from GIPHY', 'tiktok': 'the picture of a TikTok post', 'youtube': 'the picture of a YouTube video'}.get(v.get('from'), 'a picture')
            return '  %s: %s by %s%s%s%s' % (k, what, v.get('by') or '?', ' (%s)' % v['date'] if v.get('date') else '',
                                             ', posted with the words "%s"' % v['title'][:140] if v.get('title') and v.get('from') != 'gif' else '', '. It shows: %s' % v['seen'] if v.get('seen') else '')
        return '  %s: %s%s. Its post says: "%s"' % (k, 'video, %ss' % v.get('seconds') if v['kind'] == 'clip' else 'picture', ' from ' + v['by'], v['about'][:200])
    alist = '\n'.join(one(k, v) for k, v in assets_of(m).items()) or '  (none: every line needs a hunt)'
    plist = '\n'.join('  post%d: %s (%s), %s, %s likes%s: "%s"' % (i, p['handle'], p['name'], p['date'], p['likes'], ', replying to @' + p['reply_to'] if p.get('reply_to') else '', p['text'][:500]) for i, p in enumerate(m.get('posts', []))) or '  (none)'
    slist = '\n\n'.join('OUTLET %s: "%s"\n%s' % (x['publisher'], x['title'], x['excerpt'][:2600]) for x in m.get('sources', []) if 'fonts.' not in x['publisher']) or '(none fetched)'
    return alist, plist, slist


def story_user(m, ask):
    slug, want = m['url'].rstrip('/').split('/')[-1], 'meme' if is_meme(m) else 'story'
    ex = [e for e in json.load(open(os.path.join(HERE, 'examples.json'), encoding='utf-8')) if e['slug'] != slug]
    ex = sorted(ex, key=lambda e: e.get('kind', 'story') != want)[:2]      # a meme studies the meme example first, a story the story ones
    examples = '\n\n'.join('EXAMPLE (%s). Vote: %s or %s.\n%s' % (e['about'], e['vote'][0], e['vote'][1], '\n'.join('%d. %s' % (i + 1, l) for i, l in enumerate(e['lines']))) for e in ex)
    alist, plist, slist = listing(m)
    return ('%s\n\nTHE STORY\nTitle: %s\nPage: %s\nPublished: %s\n\nOUR PAGE (already fact-checked; its attributions are the safe wording):\n%s\n\nTHE POSTS THE PAGE CITES (ids for receipts):\n%s\n\n'
            'WHAT THE VIDEO CAN SHOW (ids for "show"):\n%s\n\nWHAT THE OUTLETS WROTE:\n%s\n\n%s'
            % (examples, m['title'], m['url'], m.get('published', ''), m['page_text'][:6500], plist, alist, slist, ask))


def stager_user(m, sc):
    alist, plist, _ = listing(m)
    v = sc.get('vote') if isinstance(sc.get('vote'), dict) else {}
    return ('THE STORY: %s (%s)\n\nTHE SCRIPT (final; the id is in front of each line):\n%s\n\nTHE VOTE: %s | %s | %s\n\nTHE POSTS THE PAGE CITES (ids for receipts):\n%s\n\nWHAT THE VIDEO CAN SHOW (ids for "show"):\n%s\n\n'
            'THE OUTLETS (for chips and for who said a quote): %s\nA quote card may only use words that the script itself quotes, or words of a post above.\n\nDecide what is on screen.'
            % (m['title'], m['url'], '\n'.join('%s: %s' % (l['id'], l['text']) for l in sc['lines']), v.get('question', ''), (v.get('a') or {}).get('word', ''), (v.get('b') or {}).get('word', ''),
               plist, alist, ', '.join('%s ("%s")' % (x['publisher'], x['title'][:70]) for x in m.get('sources', [])) or 'none'))


def prompt_for(m):
    return system_for('director', m), story_user(m, 'Write the plan.')


def tidy_script(sc):
    """The writer's lines made usable: only lines with words, one id each, "hook" first and "vote" last."""
    for l in (sc.get('lines') or []) + (sc.get('openings') or []):      # the writer gives each line sentence by sentence ("say"): joined here
        if isinstance(l, dict) and isinstance(l.get('say'), list):
            parts = [re.sub(r'\s+', ' ', str(x)).strip() for x in l['say'] if str(x).strip()]
            l['text'] = ' '.join(x if x[-1] in '.!?…"”' else x + '.' for x in parts)
    lines, seen = [l for l in (sc.get('lines') or []) if isinstance(l, dict) and str(l.get('text', '')).strip() and l.get('id') != 'site'], set()
    for i, l in enumerate(lines):
        base = 'hook' if i == 0 else 'vote' if i == len(lines) - 1 else re.sub(r'[^a-z0-9]', '', str(l.get('id') or '').lower()) or 'l%d' % i
        l['id'] = base if base not in seen else '%s%d' % (base, i)
        seen.add(l['id'])
        l['text'] = re.sub(r'\s+', ' ', str(l['text'])).strip()
    sc['lines'] = lines
    return sc


def opening_faults(text):
    """The form of an opening, as code can read it."""
    out, ss, low = [], sentences(text), (text or '').lower().strip()
    if not 2 <= len(ss) <= 4:
        out.append('it has %d sentence%s: write 2 or 3 short ones' % (len(ss), '' if len(ss) == 1 else 's'))
    if not 8 <= words(text) <= 34:
        out.append('it has %d words: write 10 to 30' % words(text))
    if ss and words(ss[0]) > 12:
        out.append('its first sentence has %d words: 10 at most' % words(ss[0]))
    if re.match(r'(the|a new|in|on|recently|today|according|there (?:is|are))\b', low):
        out.append('it starts with "%s": start with the thing itself' % low.split()[0])
    if re.match(r'(pick|guess|watch|imagine|meet|check out|get ready|wait|stop|listen|choose)\b', low):
        out.append('it orders the viewer around before showing anything ("%s ..."): start with the thing itself' % low.split()[0].capitalize())
    if ARTICLE.search(text or ''):
        out.append('it sounds like an article ("%s")' % ARTICLE.search(text).group(0).strip(', '))
    return out


def voice_faults(lines):
    """The script read for its SOUND: long sentences, article words, lines that all start alike, no turn."""
    out, every = [], []
    for i, l in enumerate(lines):
        ss = sentences(l['text'])
        every += ss
        long_ = [x for x in ss if words(x) > 20 and not re.search(r'["“”]', x)]      # a real quote is not cut to fit
        if long_:
            out.append('line %d has a sentence of %d words ("%s ..."): break it into two or three short ones' % (i + 1, words(long_[0]), ' '.join(long_[0].split()[:6])))
        elif len(ss) == 1 and words(l['text']) > 16 and l.get('id') != 'vote':
            out.append('line %d is one long sentence: say it in two or three short ones' % (i + 1))
        if i and ARTICLE.search(l['text']):
            out.append('line %d sounds like an article ("%s"): say it the way you would tell a friend' % (i + 1, ARTICLE.search(l['text']).group(0).strip(', ')))
    if every and sum(words(x) for x in every) / len(every) > 11:
        out.append('the sentences average %d words (the examples average 7): cut the long ones in two' % round(sum(words(x) for x in every) / len(every)))
    first = [tokens(l['text'])[0] for l in lines if tokens(l['text'])]
    for w in sorted(set(first)):
        if first.count(w) >= 3:
            out.append('%d lines start with "%s": start them differently' % (first.count(w), w))
    if len(lines) > 4 and not any(re.match(r'(but|then|now|same|meanwhile|until|except|and then)\b', l['text'].lower()) for l in lines[1:-1]):
        out.append('the script has no turn: start one line in the middle with "But", "Then", "Now" or "Same night"')
    return out


def choose_opening(sc, m, log):
    """Five openings were written. Code throws out those that break the form; a second reader, asked as the viewer,
    picks the one that would stop a thumb. Line 1 becomes that one. Returns what happened (for the report)."""
    lines = sc.get('lines') or []
    if not lines:
        return {'by': 'nobody: the writer gave no lines'}
    ops = [re.sub(r'\s+', ' ', str(o.get('text', ''))).strip() for o in (sc.get('openings') or []) if isinstance(o, dict)]
    ops, cur = [o for o in ops if o][:6], lines[0]['text']
    good = [o for o in dict.fromkeys(ops + [cur]) if not opening_faults(o) and not re.search(r'\d', o)]
    info = {'written': ops, 'writer_pick': cur, 'in_form': len(good)}
    if len(good) < 2:
        if good and opening_faults(cur):
            lines[0]['text'] = good[0]
        info.update(by='the form rules alone', picked=lines[0]['text'])
        log('director: %d openings written, %d keep the form: no choice to make; line 1 is "%s"' % (len(ops), len(good), lines[0]['text']))
        return info
    user = ('THE VIDEO IS ABOUT: %s\n%s\n\nOPENINGS\n%s\n\nScore EACH opening from 1 to 10: how likely are you to keep watching after hearing it?\n'
            'HIGH: it puts something concrete in front of me that I can picture (a thing, a number, a name), it is strange or it starts a fight I have an opinion on, and it leaves a question open.\n'
            'LOW: it is vague ("absurd", "chaos", "endless", "sparks memes"), it sums the story up, it tells me what to do before showing me anything, or it sounds like an article.\n'
            'JSON: {"scores":[{"n":1,"score":7},{"n":2,"score":4}],"why":"max 12 words about the best one"}'
            % (m.get('title', ''), (m.get('summary') or '')[:300], '\n'.join('%d. %s' % (i + 1, o) for i, o in enumerate(good))))
    try:
        j, model = ai.ask_json(JUDGE, user, kind='reader', temperature=0.2, timeout=60, max_tokens=600)
        got = {int(x['n']): float(x['score']) for x in j.get('scores') or [] if isinstance(x, dict) and 1 <= int(x.get('n', 0)) <= len(good)}
        if not got:
            raise ValueError('no scores')
        top = max(got.values())
        best = cur if cur in good and got.get(good.index(cur) + 1) == top else good[max(got, key=lambda n: (got[n], -n)) - 1]      # a tie goes to the writer's own pick
        info.update(scores=[[o, got.get(i + 1)] for i, o in enumerate(good)], why=str(j.get('why', ''))[:120])
    except (ai.AIError, TypeError, ValueError, IndexError, KeyError):
        best, model = (cur if cur in good else good[0]), 'the form rules alone (no reader answered)'
    lines[0]['text'] = best
    info.update(by=model, picked=best)
    log('director: %d openings written, %d keep the form; picked by %s: "%s"' % (len(ops), len(good), model, best))
    return info


def merge(sc, staged):
    """The writer's words and the picture editor's screen, line by line. The words always win: the editor cannot change one."""
    slines = [l for l in (staged.get('lines') or []) if isinstance(l, dict)]
    by = {re.sub(r'[^a-z0-9]', '', str(l.get('id', '')).lower()): l for l in slines}
    lines = []
    for i, l in enumerate(sc['lines']):
        e = by.get(l['id']) or (slines[i] if len(slines) == len(sc['lines']) else {})
        lines.append({'id': l['id'], 'text': l['text'], 'show': e.get('show'), 'overlays': e.get('overlays') or [], 'caps': e.get('caps') or l.get('caps') or []})
    return {'angle': sc.get('angle'), 'lines': lines, 'vote': sc.get('vote'), 'people': sc.get('people'), 'hunts': staged.get('hunts'), 'stock': staged.get('stock'), 'post': staged.get('post')}


def split_keep(t):
    """A line as its sentences with nothing lost: joined with a space they give the line back."""
    return [x.strip() for x in re.findall(r'.+?(?:[.!?…]+["”’\']*(?=\s|$)|$)', (t or '').strip(), flags=re.S) if x.strip()]


def trim_long(p, log):
    """A script that runs too long is cut by REMOVING whole sentences. Nothing is rewritten, so nothing new can be
    claimed: when the writer was asked to shorten, it rewrote, and the fact check then refused the shorter versions.
    A reader chooses the sentences; if none answers, or it leaves too much, the last sentences of the longest lines go.
    Never removed: the opening, the vote, the first sentence of a line, a quote. Returns True when something was cut."""
    lo, hi = CFG['length']['words_min'], CFG['length']['words_max']
    lines = [l for l in (p.get('lines') or []) if isinstance(l, dict) and str(l.get('text', '')).strip() and l.get('id') != 'site']
    total = lambda: sum(words(' '.join(x)) for x in sent)
    sent = [split_keep(l['text']) for l in lines]
    before = total()
    if before <= hi + 12 or len(lines) < 4:
        return False
    free = lambda i, k: 0 < i < len(lines) - 1 and k > 0 and not re.search(r'["“”]', sent[i][k])
    ids = {'%d.%d' % (i + 1, k + 1): (i, k) for i, ss in enumerate(sent) for k in range(len(ss))}
    rows = '\n'.join('%s%s %s' % (n, '' if free(i, k) else '*', sent[i][k]) for n, (i, k) in ids.items())
    gone, by = set(), 'the length rule alone'
    try:
        j, by = ai.ask_json('You shorten a voice-over by deleting whole sentences. You never rewrite a word. Strict JSON only.',
                            'This voice-over has %d words. It may have %d at most. Choose whole sentences to DELETE, about %d words in all: asides, repeated ideas, the least needed details. '
                            'Keep it understandable: never delete a sentence that the next one needs. Sentences marked * cannot be deleted.\n\n%s\n\nJSON: {"delete":["3.2","5.4"]}'
                            % (before, hi, before - hi + 4, rows), kind='reader', temperature=0.2, timeout=60, max_tokens=500)
        for n in j.get('delete') or []:
            i, k = ids.get(str(n).strip().rstrip('*'), (-1, -1))
            cut = sum(words(sent[a][b]) for a, b in gone | {(i, k)}) if i >= 0 else 0
            if i >= 0 and free(i, k) and before - cut >= lo and before - sum(words(sent[a][b]) for a, b in gone) > hi:
                gone.add((i, k))
    except ai.AIError:
        pass
    left = lambda: before - sum(words(sent[a][b]) for a, b in gone)
    while left() > hi + 6:                                     # still too long: the last free sentence of the longest line goes
        cand = [(sum(words(x) for k, x in enumerate(ss) if (i, k) not in gone), i, max(k for k in range(len(ss)) if free(i, k) and (i, k) not in gone))
                for i, ss in enumerate(sent) if any(free(i, k) and (i, k) not in gone for k in range(len(ss)))]
        if not cand:
            break
        _, i, k = max(cand)
        if left() - words(sent[i][k]) < lo:
            break
        gone.add((i, k))
    if not gone:
        return False
    for i, l in enumerate(lines):
        l['text'] = ' '.join(x for k, x in enumerate(sent[i]) if (i, k) not in gone)
    log('director: the script had %d words (%d at most): %d sentences were removed, chosen by %s, and nothing was rewritten: %d words now' % (before, hi, len(gone), by, left()))
    return True


def story_for_repair(p):
    return {k: p.get(k) for k in ('angle', 'lines', 'vote', 'people', 'hunts', 'stock', 'post')}


def fix_and_check(p, m):
    """Returns (plan with every mechanical fix applied, [reasons the AI must fix], [hard reasons the plan is refused])."""
    soft, hard = [], []
    cor, have = corpus(m), assets_of(m)
    meme, cards = is_meme(m) and any(v.get('whole') for v in have.values()), 0
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
    hunts = {h['id']: h for h in (p.get('hunts') or [])[:2 if meme else CFG['footage']['max_hunts']] if isinstance(h, dict) and re.fullmatch(r'hunt\d+', str(h.get('id', ''))) and len(str(h.get('query', '')).split()) >= 2}
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
            elif k in CARDS and not card and not (meme and cards >= 2):      # a meme stays visible: two cards in the whole video
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
                card += 1; cards += 1; kept.append(o)
        l['overlays'] = kept
        l['show']['mode'] = 'under' if card else 'sharp'      # decided here, not by the AI: footage is sharp unless a card needs to be read over it
        caps = []
        for c in l.get('caps') or []:
            if isinstance(c, dict) and str(c.get('say', '')).strip() and str(c.get('show', '')).strip() and norm(c['say']) in norm(t):
                caps.append({'say': str(c['say']).strip(), 'show': str(c['show']).strip()[:14]})
        l['caps'] = caps
    if not any(o.get('k') == 'stamp' for o in lines[0]['overlays']):
        soft.append('line 1 needs a "stamp": the key number or word of the hook')
    if sum(1 for l in lines for o in l['overlays'] if o.get('k') in ('receipt', 'quote')) == 0 and (m.get('posts') or not meme):
        soft.append('no proof on screen: show at least one post as a "receipt" or one real quote as a "quote"')
    soft += ['line 1 (the opening): ' + x for x in opening_faults(lines[0]['text'])] + voice_faults(lines)
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
            long_ = [x for x in sentences(t) if words(x) > 20 and not re.search(r'["“”]', x)]
            if long_:
                soft.append('line "%s" has a sentence of %d words: break it into two short ones' % (lid, words(long_[0])))
            if ARTICLE.search(t):
                soft.append('line "%s" sounds like an article ("%s"): say it the way you would tell a friend' % (lid, ARTICLE.search(t).group(0).strip(', ')))
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
    p['disputed_faults'] = []
    if disputed and word:
        loose = [x['t'] for x in texts + items if norm(word) not in norm(x['t'])]
        p['disputed_faults'] = loose
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


def labels_causes(plan, m):
    """A disputed word, read once more for neutrality only: is the word, or a verdict, put on a cause or a group?
    Returns the reasons to fix ([] = neutral), or None when no model could do the reading."""
    S = plan['slang']
    shown = (['TEST %s: %s' % ('ABC'[i], x['t']) for i, x in enumerate(S['quiz']['texts'])]
             + ['ROUND %d: %s -> %s' % (i + 1, it['t'], S['round']['yes'] if it['is'] else S['round']['no']) for i, it in enumerate(S['round']['items'])]
             + ['FINAL: %s -> %s or %s' % (S['final']['setup'], S['final']['a']['word'], S['final']['b']['word'])])
    spoken = [l['text'] for l in plan['lines'] if l['id'].startswith('round') or l['id'] in ('hook', 'final')]
    sys_ = ('You check a short video about a disputed word ("%s") for ONE thing: neutrality. The video may sort SENTENCES THAT USE THE WORD by the sense in which that sentence uses it. '
            'It may NOT put the word, or a verdict, on a real cause, movement, group, identity, belief, religion or party, nor on a stance towards one (supporting, protesting, mocking or criticising something). '
            'Allowed: "That movie is so woke, I walked out" -> INSULT (it only says how the word is used in that sentence). '
            'NOT allowed: "Supporting X rights" -> ORIGINAL; "Calling out racism is woke" -> ORIGINAL; "Mocking a diversity plan" -> INSULT (each stamps a cause or its critics). '
            'List every item that is not allowed. Strict JSON only: {"problems":[{"item":"TEST A, ROUND 2 or FINAL","text":"the words","why":"short"}]} (an empty list if every item is allowed).' % S['word'])
    try:
        j, _ = ai.ask_json(sys_, 'ON SCREEN\n%s\n\nSPOKEN\n%s' % ('\n'.join(shown), '\n'.join(spoken)), kind='reader', temperature=0.1, timeout=ai.CONFIG['ai'].get('timeout', 100), max_tokens=2500)
    except ai.AIError:
        return None
    return ['DISPUTED WORD: %s puts a verdict on a cause or a group ("%s"); make it a sentence someone says that contains the word, judged only for the sense it uses'
            % (str(x.get('item', '?'))[:14], str(x.get('text', ''))[:70]) for x in j.get('problems', []) if isinstance(x, dict)][:8]


def unsupported(plan, m):
    """A second, adversarial reading: every spoken sentence and on-screen text against the material. Returns the reasons to fix."""
    def screen(o):
        return ' '.join([str(o.get('t') or o.get('name') or '')] + [r.get('t', '') for r in o.get('rows', [])] + [x.get('label', '') + ' ' + x.get('big', '') for x in o.get('items', [])]).strip()
    script = '\n'.join('%d. %s  [on screen: %s]' % (i + 1, l['text'], ' / '.join(screen(o) for o in l['overlays'])) for i, l in enumerate(plan['lines']))
    mat = 'OUR PAGE:\n%s\n\nPOSTS:\n%s\n\nOUTLETS:\n%s' % (m['page_text'][:6500], '\n'.join('%s: %s' % (p['handle'], p['text'][:500]) for p in m.get('posts', [])), '\n\n'.join(s['excerpt'][:2600] for s in m.get('sources', [])))
    shown = ['%s by %s%s%s' % (v.get('from'), v.get('by'), ', posted with the words "%s"' % v['title'][:160] if v.get('title') else '', '. A picture model saw in it: %s' % v['seen'] if v.get('seen') else '')
             for v in (m.get('meme_assets') or {}).values() if v.get('usable', True)]
    if shown:                                                  # what the video shows is material too: a line may say what is on screen
        mat += '\n\nTHE PICTURES THE VIDEO SHOWS (the meme itself, and posts that use it):\n' + '\n'.join(shown)
    sys_ = ('You are a strict fact checker. You get a short video script and the ONLY material it may use. List every statement in the script (spoken or on screen) that the material does not support: '
            'invented facts, numbers or names that differ, superlatives and predictions the material does not make ("the best in the world", "they will lose"), a claim about wrongdoing stated without who says it, '
            'a guessed gender. A fair summary of what the material says is supported. Questions and the vote are not claims. Lines marked EXAMPLE are made-up everyday illustrations of how the word is used: judge only whether they fit the meaning the material gives. Strict JSON only: {"problems":[{"line":1,"text":"the words","why":"short"}]} (an empty list if all is supported).')
    try:
        j, _ = ai.ask_json(sys_, 'MATERIAL\n%s\n\nSCRIPT\n%s' % (mat, script), kind='reader', temperature=0.1, timeout=ai.CONFIG['ai'].get('timeout', 100), max_tokens=6000)
    except ai.AIError:
        return None                                           # no checker answered: said in the report, the plan is kept
    return ['line %s says "%s": not in the material (%s); say only what the material says, or cut it' % (p.get('line'), str(p.get('text', ''))[:70], str(p.get('why', ''))[:80])
            for p in j.get('problems', []) if isinstance(p, dict)][:8]


def direct(m, log=print, work=None, budget=None):
    """The plan: one draft, then up to two corrections (code checks + a fact check on every version). The draft of a story is
    made in three small jobs: the words (five openings, then the script), the pick of the opening, the pictures. A meme's own
    pictures are fetched and looked at before a word is written. THE BEST VERSION IS
    KEPT: a correction that comes back worse (too short for a minute, more unsupported statements, more faults, fewer
    words) is thrown away, and the next correction starts again from the best one. With a work folder the progress is
    kept after every AI answer (plan_progress.json): a stopped run, or a caller with a time limit (budget, in seconds:
    exit code 3 = run again), continues where it was instead of paying for the same answers twice."""
    slang_page = '/slang/' in m['url']                         # a word page is told as a game (the slang format), a story page as a story
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

    def read(plan, hard):
        """Every version is read by a second model: against the material, and (a disputed word) for neutrality.
        [] = nothing found, None = a reading could not be run."""
        if hard:
            return []
        found = unsupported(plan, m)
        if slang_page and plan.get('slang', {}).get('disputed'):
            sides = labels_causes(plan, m)
            found = None if found is None or sides is None else found + sides
        return found

    def rank(plan, soft, hard, facts):
        """Lower is better: broken, then too short to reach a minute, then unsupported statements, then how far under the
        wanted length (in steps of ten words: length weighs more than a small fault), then the other faults, then words."""
        w = plan.get('words', 0)
        return [len(hard) + (1 if plan.get('incomplete') else 0), 0 if w >= lo - 25 else 1, len(facts or []), -(-max(0, lo - w) // 10), len(soft), -min(w, hi)]

    if is_meme(m) and work:                                    # a meme's own pictures: fetched and looked at, one by one, before a word is written
        import memes
        if st.get('memes') is None:
            st['memes'] = memes.collect(m, work, log)
            keep()
        if any('looked' not in a for a in st['memes'].values()):
            memes.look(st['memes'], m, work, log, keep)
        if not st.get('sorted'):
            memes.sort_topic(st['memes'], m, log)
            st['sorted'] = True
            keep()
    m['meme_assets'] = st.get('memes') or {}
    system, user = prompt_slang(m) if slang_page else prompt_for(m)
    if st['plan'] is None and slang_page:
        st['plan'], st['model'] = ai.ask_json(system, user, temperature=0.7, timeout=wait, max_tokens=12000, effort='medium', patient=True)
        keep()
    elif st['plan'] is None:                                    # a story: the words, then the opening is picked, then the pictures
        if st.get('script') is None:
            sc, st['model'] = ai.ask_json(system_for('writer', m), story_user(m, 'Write the five openings, pick the strongest, then the script.'), temperature=0.8, timeout=wait, max_tokens=6000,
                                          effort='medium', patient=True)
            st['script'] = tidy_script(sc)
            keep()
        if not st.get('opening'):
            st['opening'] = choose_opening(st['script'], m, log)
            keep()
        staged, by = ai.ask_json(system_for('picture editor', m), stager_user(m, st['script']), temperature=0.3, timeout=wait, max_tokens=7000)
        st['plan'] = merge(st['script'], staged)
        log('director: the words are by %s, the pictures by %s' % (st['model'], by))
        keep()
    if not slang_page and st.get('best') is None and not st.get('trimmed'):      # a draft that runs long loses sentences before it is checked
        trim_long(st['plan'], log)
        st['trimmed'] = True
        keep()
    while True:
        plan, soft, hard = check(st['plan'], m)
        if st['facts_for'] != st['round']:                        # every version is fact-checked, the last one included
            st['facts'] = read(plan, hard)
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
               % (json.dumps(slang_for_repair(base) if slang_page else story_for_repair(base), ensure_ascii=False), '\n- '.join(todo), base.get('words', 0), max(lo, base.get('words', 0)), hi))
        try:
            st['plan'], st['model'] = ai.ask_json(system, user + '\n\n' + fix, temperature=0.4, timeout=wait, max_tokens=12000, patient=True)
        except ai.OutOfTime:
            st['fix_tries'] -= 1                                    # stopped by the caller's time limit: not a try the correction had
            if ck:
                json.dump(st, open(ck, 'w', encoding='utf-8'), ensure_ascii=False)
            raise
        except ai.AIError as e:
            log('director: the correction got no answer (%s); the best version stands' % str(e)[:100])
            break
        st['round'] += 1
        keep()
    if not slang_page and not st.get('trimmed_end') and st['best']['plan'].get('words', 0) > hi + 12:
        st['trimmed_end'] = True                                    # the best version still runs long (a correction added words): sentences are removed, never rewritten
        cutp = copy(st['best']['plan'])
        if trim_long(cutp, log):
            g, gsoft, ghard = check(cutp, m)
            gfacts = read(g, ghard)
            gr = rank(g, gsoft, ghard, gfacts)
            log('director: cut to %d words | fact check: %s | %s' % (g.get('words', 0), 'NOT RUN' if gfacts is None else '%d problems' % len(gfacts) if gfacts else 'clean',
                'KEPT' if gr < st['best']['rank'] else 'not better: thrown away'))
            if gr < st['best']['rank']:
                st['best'] = {'plan': copy(g), 'model': st['best']['model'], 'rank': gr, 'facts': gfacts}
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
            gfacts = read(g, ghard)
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
    # the two refusals (owner 2026-10-08): what the second reading refused does not go out as a note in a report
    if facts:
        hard.append('%d statements are still in the script that the second reading refused (not in the material, or a verdict on a cause or a group): %s' % (len(facts), ' | '.join(f[:110] for f in facts[:3])))
    if slang_page and plan.get('slang', {}).get('disputed'):
        if facts is None:
            hard.append('the word is disputed and the neutrality reading could not be run')
        if plan.get('disputed_faults'):
            hard.append('the word is disputed and these texts are not sentences that use it: %s' % ' | '.join(plan['disputed_faults'])[:200])
    if hard:
        raise SystemExit('PLAN REFUSED: ' + '; '.join(hard))
    log('director: the plan used is the one by %s, %d words, %s' % (st['best']['model'], plan.get('words', 0),
        'fact check not run' if facts is None else '%d unsupported statements left' % len(facts) if facts else 'fact check clean'))
    plan.update({'url': m['url'], 'title': m['title'], 'model': st['best']['model'], 'assets': {} if slang_page else assets_of(m), 'kind': 'slang' if slang_page else 'meme' if is_meme(m) and any(v.get('usable', True) for v in m['meme_assets'].values()) else 'story',
                 'opening': st.get('opening'), 'left_open': soft2 + (facts or []),
                 'fact_checked': facts is not None, 'unsupported_left': len(facts or [])})
    return add_site_line(plan)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    work = sys.argv[1]
    mat = json.load(open(os.path.join(work, 'material.json'), encoding='utf-8'))
    if len(sys.argv) > 2:                                          # a caller with a time limit: no AI answer is awaited past it
        ai.DEADLINE = time.time() + float(sys.argv[2]) + 75
    try:
        pl = direct(mat, lambda *a: print(*a, flush=True), work, float(sys.argv[2]) if len(sys.argv) > 2 else None)
    except ai.OutOfTime as e:
        print('director: %s; run again' % e, flush=True)
        sys.exit(3)
    except SystemExit as e:
        if str(e.code).startswith('PLAN REFUSED'):                 # the reason goes into the run's report
            open(os.path.join(work, 'plan_refused.txt'), 'w', encoding='utf-8').write(str(e.code))
        raise
    json.dump(pl, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    for ln in pl['lines']:
        print('%-8s [%s %s] %s\n         %s' % (ln['id'], ln['show']['asset'], ln['show']['mode'], ln['text'], ' | '.join('%s:%s' % (o['k'], o.get('t') or o.get('name') or o.get('post') or len(o.get('rows', o.get('items', [])))) for o in ln['overlays'])))
    print('vote:', json.dumps(pl.get('vote'), ensure_ascii=False), '| hunts:', json.dumps(pl.get('hunts'), ensure_ascii=False), '| stock:', pl.get('stock'), '| sensitive:', pl['sensitive'])
