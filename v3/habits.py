"""THE HABITS: what our other videos already said, so the next one does not say it again.
Every video the system finishes leaves its spoken lines in store/said.json. From them two things are read:
  - the wordings that came back in two or more DIFFERENT stories ("here is the twist", "so whose side are you on"):
    the system's own stock phrases, found by counting, not listed by hand;
  - the openings of the last videos.
The writer is shown both and told not to reuse them; a script that does is sent back with the wording named.
What the format itself needs every time (the vote's "comment one word", the closing line) is never counted."""
import glob
import json
import os
import re
import time

HERE = os.path.dirname(os.path.abspath(__file__))
STORE = os.path.join(HERE, 'store', 'said.json')
FIXED = ('comment one word', 'link in bio', 'gen z hype', 'the full timeline and every receipt')
EMPTY = set('the a an of to in on is it and or for he she they his her their this that these those was were are with at by from as be has have had not no so but then now one two its im i you we'.split())


def toks(text):
    return re.findall(r"[a-z0-9]+", (text or '').lower().replace('’', '').replace("'", ''))


def grams(text, n=4):
    for f in FIXED:
        text = re.sub(re.escape(f), ' | ', text or '', flags=re.I)
    out = set()
    for part in re.split(r'[|.!?…]+', text):                  # a wording lives inside one sentence
        t = toks(part)
        out |= {' '.join(t[i:i + n]) for i in range(len(t) - n + 1) if not set(t[i:i + n]) <= EMPTY}
    return out


def _load():
    if os.path.isfile(STORE):
        try:
            return json.load(open(STORE, encoding='utf-8'))
        except Exception:  # noqa: BLE001
            return []
    seen = {}                                                  # first use on a machine: the videos already made here
    for f in sorted(glob.glob(os.path.join(HERE, 'work', '*', 'out', 'plan.json')), key=os.path.getmtime):
        try:
            p = json.load(open(f, encoding='utf-8'))
        except Exception:  # noqa: BLE001
            continue
        if p.get('url') and p.get('lines') and p.get('format') != 'slang':
            seen[p['url']] = {'url': p['url'], 'date': time.strftime('%Y-%m-%d', time.localtime(os.path.getmtime(f))), 'lines': [[l['id'], l['text']] for l in p['lines'] if l.get('id') != 'site']}
    return list(seen.values())


def learn(plan):
    """A finished plan joins the store (one entry per page: a new video of the same page replaces the old one)."""
    if not plan.get('url') or plan.get('format') == 'slang':
        return
    rows = [r for r in _load() if r.get('url') != plan['url']]
    rows.append({'url': plan['url'], 'date': time.strftime('%Y-%m-%d'), 'lines': [[l['id'], l['text']] for l in plan.get('lines') or [] if l.get('id') != 'site']})
    os.makedirs(os.path.dirname(STORE), exist_ok=True)
    json.dump(rows[-80:], open(STORE, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)


def stock(url, seen_in=2):
    """{wording: in how many other stories}: four words in a row that two or more other videos already said."""
    count = {}
    for r in _load():
        if r.get('url') == url:
            continue
        for g in set().union(*[grams(t) for _, t in r.get('lines') or []] or [set()]):
            count[g] = count.get(g, 0) + 1
    return {g: n for g, n in count.items() if n >= seen_in}


def openers(url, k=8):
    rows = [r for r in _load() if r.get('url') != url][-k:]
    return [re.split(r'(?<=[.!?])\s+', r['lines'][0][1])[0] for r in rows if r.get('lines')]


def faults(lines, url):
    """What a script reuses of our other videos, one fault per line at most."""
    st, out = stock(url), []
    if not st:
        return out
    for i, l in enumerate(lines):
        hit = sorted((g for g in grams(l.get('text', '')) if g in st), key=lambda g: -st[g])
        if hit:
            out.append('line %d reuses a wording of our other videos ("%s", already in %d of them): say it another way, in this story\'s own words' % (i + 1, hit[0], st[hit[0]]))
    return out[:4]


def block(url):
    """For the writer: our stock phrases and last openings, so this script sounds like no other."""
    st = sorted(stock(url).items(), key=lambda kv: -kv[1])[:14]
    ops = openers(url)
    if not st and not ops:
        return ''
    return ('OUR OTHER VIDEOS ALREADY SAID THIS (a viewer who saw two of them hears the pattern; none of it may come back, in these words or in the same skeleton):\n'
            + (''.join('- "%s"\n' % g for g, _ in st)) + ('Their openings (never the same shape twice in a row):\n' + ''.join('- %s\n' % o for o in ops[-6:]) if ops else '') + '\n')
