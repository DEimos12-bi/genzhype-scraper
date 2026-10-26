"""THE CUT: every line of the script gets footage for its whole length, cut on spoken words, and nothing is ever a
blank card. Rules, in order:
  - a line shows the asset the director asked for when the eyes found it usable; otherwise the best footage left;
  - a sharp shot lasts 4 seconds at most, a blurred one 6: a longer line is cut in two or three at a word, each part a
    different moment of the clip (best moments first, none used twice);
  - a clip whose main subject is a person's face is shown sharp only on a line that names a person its own post names;
    otherwise it goes blurred (no face under the wrong name);
  - stock footage and clips the eyes refused are only ever used blurred, behind cards;
  - small landscape clips are shown as a card on a blurred copy instead of being enlarged three times.
Then it writes comp.json / comp.js (shots, voice timings, sound cues, the plan) and extracts the frames.
usage: shots.py <work folder>"""
import json
import math
import os
import re
import shutil
import subprocess
import sys

import beats
import brand

FPS = 30
MAX_SHARP, MAX_UNDER = 4.0, 6.0


def norm(s):
    return re.sub(r'[^a-z0-9]+', ' ', (s or '').lower()).strip()


def wt(line, word, lead=0.06):
    w0 = norm(word).split(' ')[0] if word else ''
    for w in line['words']:
        if w0 and norm(w['w']) == w0:
            return round(w['s'] - lead, 3)
    return round(line['s'], 3)


def split_times(line, t0, t1, parts):
    """Cut points inside a line: at words, as close as possible to equal parts, preferring a word after a pause."""
    if parts <= 1:
        return []
    cuts, words = [], line['words']
    for k in range(1, parts):
        want = t0 + (t1 - t0) * k / parts
        best, bs = None, 1e9
        for i in range(1, len(words)):
            t = words[i]['s'] - 0.06
            if not t0 + 1.2 <= t <= t1 - 1.2 or any(abs(t - c) < 1.2 for c in cuts):
                continue
            gap = words[i]['s'] - words[i - 1]['e']
            score = abs(t - want) - min(0.5, gap * 2)          # a breath is the natural place for a cut
            if score < bs:
                best, bs = t, score
        if best is not None:
            cuts.append(round(best, 3))
    return sorted(cuts)


class Supply:
    """Hands out moments of the fetched footage, best first, none twice."""

    def __init__(self, assets):
        self.a = {k: v for k, v in assets.items() if v.get('file') and not (v.get('whole') and not v.get('usable', True))}      # a meme example that shows something else is not footage
        self.used = {k: [] for k in self.a}
        self.uses = {k: 0 for k in self.a}                    # lines that showed it: the least shown comes first
        self.seen = {k: set() for k in self.a}                # the scenes of each clip that were shown

    def clips(self, sharp):
        out = [k for k, v in self.a.items() if v['kind'] in ('clip', 'hunt', 'stock') and (not sharp or (v['kind'] != 'stock' and v.get('usable', True)))]
        return sorted(out, key=lambda k: (not self.a[k].get('usable', True), not self.a[k].get('eyes', {}).get('exact', True), len(self.used[k]), -len(self.a[k].get('windows', []))))

    def photos(self):
        return sorted([k for k, v in self.a.items() if v['kind'] == 'photo'], key=lambda k: self.uses[k])

    def spread(self, k):
        """A clip asked for again when the story has another of the same thing (a game's second trailer, another clip of
        the same channel) that was shown less: that one plays. The same subject, a new picture."""
        v = self.a[k]
        if not v.get('group') or v['kind'] != 'clip':
            return k
        same = [x for x, o in self.a.items() if o.get('group') == v['group'] and o['kind'] == 'clip' and o.get('usable', True) and (o.get('h', 0) > o.get('w', 0)) == (v.get('h', 0) > v.get('w', 0))]
        best = min(same or [k], key=lambda x: (self.uses[x], x != k))
        return best if self.uses[k] - self.uses[best] >= 2 else k

    def moment(self, k, dur):
        """Where a shot of `dur` seconds starts in clip k, and where its subject sits. A scene that was not shown yet comes
        first (the best rated first) and the shot stays inside it; then a good moment away from the ones shown; then the
        place farthest from everything shown, never one the eyes rated dull (a black frame, a title, a logo)."""
        v = self.a[k]
        room = max(0.0, v.get('dur', 0) - dur - 0.3)

        def start(w):
            ss, se = w.get('ss'), w.get('se')
            if ss is None or se is None:
                return min(room, max(0.0, w['t'] - 0.8))
            s = min(max(ss + 0.05, w['t'] - dur / 2), se - dur - 0.1) if se - ss >= dur + 0.2 else ss + 0.05
            return min(room, max(0.0, s))
        for fresh in (True, False):
            for w in v.get('windows', []):
                if fresh and (w.get('sc') is None or w['sc'] in self.seen[k]):
                    continue
                s = start(w)
                if all(abs(s - u) >= min(2.0 if fresh else 3.5, dur) for u in self.used[k]):
                    self.used[k].append(s)
                    self.seen[k].add(w.get('sc'))
                    return s, w.get('x', 0.5)
        tiles = v.get('tiles') or []
        near = lambda s: min(tiles, key=lambda t: abs(t['t'] - (s + dur / 2))) if tiles else None
        spots = [i * 0.5 for i in range(int(room / 0.5) + 1)]
        spots = [s for s in spots if near(s) is None or near(s)['score'] >= 2.5] or spots
        s = max(spots, key=lambda s: min([abs(s - u) for u in self.used[k]] or [0.0]))
        self.used[k].append(s)
        return s, (near(s) or {}).get('x', 0.5)


def bite_shots(pl, bl, t0, t1, sup):
    """The shots of a bite: the clip itself, each frame the one that was filmed while the word was said (ss follows the
    time of the video), cut once at a breath when it runs long: wide (a wide clip as a card, sharper) then close."""
    b, a = bl['bite'], sup.a[bl['bite']['asset']]
    sync = lambda t: round(max(0.0, b['ss'] + t - b['at']), 3)
    near = lambda t: min(a.get('windows') or [{'t': 0, 'x': 0.5}], key=lambda w: abs(w.get('t', 0) - sync(t))).get('x', 0.5)
    cuts = [t0, t1]
    if t1 - t0 > 3.8:
        ws, mid, best, bs = bl['words'], (t0 + t1) / 2, None, 1e9
        for i in range(1, len(ws)):
            t = ws[i]['s'] - 0.06
            if t0 + 1.5 <= t <= t1 - 1.3:
                score = abs(t - mid) - min(0.6, (ws[i]['s'] - ws[i - 1]['e']) * 2)
                if score < bs:
                    best, bs = round(t, 3), score
        if best:
            cuts = [t0, best, t1]
    wide = 'W' if a.get('whole') else 'C' if a['w'] > a['h'] or max(1080 / max(1, a['w']), 1920 / max(1, a['h'])) > 1.6 else 'F'
    close = 'F' if max(1080 / max(1, a['w']), 1920 / max(1, a['h'])) <= 1.6 else wide      # the close shot fills the screen only when the clip holds the pixels
    out = []
    for k in range(len(cuts) - 1):
        out.append({'id': '%s_v%d' % (pl['id'], k), 'line': bl['id'], 'beats': [], 't0': cuts[k], 't1': cuts[k + 1], 'asset': b['asset'], 'credit': a.get('credit', ''), 'dim': 0.0, 'move': 'bite', 'focus': None,
                    'card': False, 'mode': wide if k % 2 == 0 else close, 'src': 'assets/' + a['file'], 'ss': sync(cuts[k]), 'x': near(cuts[k]), 'w': a['w'], 'h': a['h']})
    sup.uses[b['asset']] += 1
    return out


def plan_shots(plan, tl, log):
    lines = {l['id']: l for l in tl['lines']}
    end = round(tl['total'] + 0.45, 3)
    sup = Supply(plan['assets'])
    if not sup.a:
        raise SystemExit('NO FOOTAGE: nothing was fetched for this story; no video is made')
    people = [norm(p.get('name', '')) for p in plan.get('people', []) if p.get('name')]
    shots, ids = [], [l['id'] for l in plan['lines']]
    # A MEME VIDEO SHOWS THE MEME: its own examples (and a searched clip the eyes found to be exactly it) carry every line,
    # shown whole, a new one every three seconds, the least shown first.
    meme_pool = lambda: sorted([k for k, v in sup.a.items() if v.get('usable', True) and (v.get('whole') or (v['kind'] == 'hunt' and v.get('eyes', {}).get('exact')))],
                               key=lambda k: (sup.uses[k], -sup.a[k].get('score', 3), k))
    meme = plan.get('kind') == 'meme' and len(meme_pool()) >= 2
    nxt = lambda last: ([k for k in meme_pool() if k != last] or meme_pool())[0]

    if plan.get('kind') == 'meme':                             # a found clip of the meme itself is shown like the meme's own examples: whole, never a window cut out of it
        for v in sup.a.values():
            if v['kind'] == 'hunt' and not v.get('game') and v.get('eyes', {}).get('exact'):
                v['whole'] = True
    asked = [(b.get('show') or {}).get('asset') for l in plan['lines'] for b in l.get('beats') or []]
    asked = [k for k in asked if k in sup.a and sup.a[k]['kind'] == 'clip' and sup.a[k].get('usable', True)]
    ranked = sorted(set(asked), key=lambda k: (-asked.count(k), -sup.a[k].get('w', 0) * sup.a[k].get('h', 0), k))      # most shown first; as often: the larger one, which fills the screen
    main, second = (ranked[0] if ranked else None), (ranked[1] if len(ranked) > 1 else None)

    def pick(last):
        """A picture for a sentence whose own choice does not exist (footage that was asked for and not found): the clip
        the plan itself shows most, so the story stays on screen; when that one is already playing, the second most shown,
        so that the fallback is never the same clip three sentences in a row; else a meme's least shown picture, else the
        story's own. (It used to take the least shown picture, and showed the twist's own picture three lines too early.)"""
        if main:
            if last == main:
                other = second or (nxt(last) if meme else None)
                if other and other != main:
                    return other
            return main
        if meme:
            return nxt(last)
        own = sorted([k for k in sup.a if sup.a[k]['kind'] in ('clip', 'photo') and sup.a[k].get('usable', True)], key=lambda k: (k == last, sup.uses[k]))
        return (own or sup.clips(True) or sup.photos() or sup.clips(False))[0]

    def face_ok(asset, text):
        """A clip whose subject is a person's face is shown sharp only under words that name a person its own post names."""
        a = sup.a[asset]
        if not (a.get('eyes', {}).get('kind') in ('stream', 'talking') or any(w.get('face') for w in a.get('windows', [])[:3])):
            return True
        own = norm(a.get('title', '')) if a['kind'] == 'hunt' else norm(a.get('about', '') + ' ' + a.get('by', ''))
        stem = re.sub(r'[^a-z]', '', str(a.get('by') or '').lower())[:9]       # the story's own subject (their post, their name in the title) may be seen under any of its sentences
        if a['kind'] != 'hunt' and len(stem) >= 5 and stem in norm(plan.get('title', '')).replace(' ', ''):
            return True
        spoken = {w.lower() for w in re.findall(r'\b[A-Z][A-Za-zÀ-ÿ]{3,}\b', text)} - {'then', 'this', 'that', 'they', 'their', 'when', 'what', 'will', 'with', 'same', 'some'}
        return any(p and p in own and p in norm(text) for p in people) or any(w in own.split() for w in spoken)
    for i, pl in enumerate(plan['lines']):
        ln = lines[pl['id']]
        t0 = 0.0 if i == 0 else round(max(0.0, ln['s'] - 0.08), 3)
        t1 = end if i == len(ids) - 1 else round(max(0.0, lines[ids[i + 1]]['s'] - 0.08), 3)
        if pl.get('beats'):                                    # the montage, sentence by sentence (beats.py)
            cards = {int(o.get('beat', 0)): int(o.get('to', o.get('beat', 0))) for o in pl.get('overlays', []) if o.get('k') in ('rows', 'quote', 'receipt', 'blocks') and not o.get('drop')}
            bl = lines.get(pl['id'] + 'bite')                 # the line ends with the story's own clip speaking: its sentences stop where the clip starts
            if bl and bl['bite'].get('first') and bl['bite']['asset'] in sup.a:      # the cold open: the clip first, then the opening's sentences
                ts = round(max(0.3, ln['s'] - 0.08), 3)
                shots += bite_shots(pl, bl, 0.0, ts, sup)
                shots += beats.for_line(pl, ln, ts, t1, sup, shots, pick, lambda b: b in cards, lambda b: cards.get(b, b), face_ok, log, first=False, single=False)
                continue
            tb = round(bl['bite']['at'] - 0.1, 3) if bl and bl['bite']['asset'] in sup.a else None
            shots += beats.for_line(pl, ln, t0, tb or t1, sup, shots, pick, lambda b: b in cards, lambda b: cards.get(b, b), face_ok, log, first=(i == 0), single=pl['id'] in ('vote', 'site'))
            if tb:
                shots += bite_shots(pl, bl, tb, t1, sup)
            continue
        want, sharp = pl['show']['asset'], pl['show']['mode'] == 'sharp'
        if i == 0:
            sharp = True                                       # the first second is always real footage, sharp
        asset = want if want in sup.a else None
        if asset and sharp and sup.a[asset]['kind'] != 'photo' and (sup.a[asset]['kind'] == 'stock' or not sup.a[asset].get('usable', True)):
            asset = None                                       # refused by the eyes: not shown sharp
        if meme and (asset not in meme_pool() or (shots and shots[-1]['asset'] == asset)):
            asset = nxt(shots[-1]['asset'] if shots else None)
        if not asset:
            # the footage this line asked for is not there. The story's OWN material (its posts' clips and pictures, the page's
            # pictures) may stand in sharp, the opening included: it is the story. Footage that was searched for something
            # else may not (the wrong game under the words is worse than a blur): it goes blurred, and an opening with
            # nothing of its own to show is refused.
            own = sorted([k for k in sup.a if sup.a[k]['kind'] in ('clip', 'photo') and sup.a[k].get('usable', True)], key=lambda k: sup.uses[k])
            if sharp and want != 'auto':
                if own:
                    asset = own[0]
                elif i == 0:
                    raise SystemExit('NO FOOTAGE OF THE SUBJECT: the opening asked for "%s" (%s) and the story has no clip or picture of its own' % (want, plan['assets'].get(want, {}).get('about', '')[:60]))
                else:
                    sharp = False
                    log('  %s: its footage (%s) is missing and the story has nothing of its own left; other footage goes blurred' % (pl['id'], want))
            if not asset:
                pool = (sup.clips(True) or own) if sharp else (sup.clips(False) or sup.photos())
                asset = (pool or sup.photos() or sup.clips(False))[0]
            if sup.a[asset]['kind'] == 'stock' or not sup.a[asset].get('usable', True):
                sharp = False
        a = sup.a[asset]
        sup.uses[asset] += 1
        if sharp and a['kind'] != 'photo' and (a.get('eyes', {}).get('kind') in ('stream', 'talking') or any(w.get('face') for w in a.get('windows', [])[:3])):
            own = norm(a.get('title', '')) if a['kind'] == 'hunt' else norm(a.get('about', '') + ' ' + a.get('by', ''))     # a hunt is trusted by its title only, never by what was searched
            named = [p for p in people if p and p in own]
            spoken = {w.lower() for w in re.findall(r'\b[A-Z][A-Za-zÀ-ÿ]{3,}\b', pl['text'])} - {'then', 'this', 'that', 'they', 'their', 'when', 'what', 'will', 'with', 'same', 'some'}
            if not any(p in norm(pl['text']) for p in named) and not any(w in own.split() for w in spoken):      # the line names nobody the clip's own post names
                other = [k for k in sup.clips(True) if k != asset and sup.a[k].get('eyes', {}).get('kind') not in ('stream', 'talking')]
                if other:
                    log('  %s: %s shows a person this line does not name; %s is shown instead' % (pl['id'], asset, other[0]))
                    asset, a = other[0], sup.a[other[0]]
                else:
                    sharp = False
                    log('  %s: %s shows a person this line does not name; shown blurred' % (pl['id'], asset))
        dur = t1 - t0
        parts = max(1, math.ceil(dur / (MAX_SHARP if sharp else MAX_UNDER)))
        if a['kind'] == 'photo':
            parts = max(1, math.ceil(dur / 5.0))
        if meme:
            parts = max(1, math.ceil(dur / (3.2 if sharp else 5.0)))
        cuts = [t0] + split_times(ln, t0, t1, parts) + [t1]
        for k in range(len(cuts) - 1):
            if meme and k:                                     # the next part of the line: the next picture of the meme
                asset = nxt(asset)
                a = sup.a[asset]
                sup.uses[asset] += 1
            s = {'id': '%s_%d' % (pl['id'], k), 'line': pl['id'], 't0': cuts[k], 't1': cuts[k + 1], 'asset': asset, 'credit': a.get('credit', ''), 'dim': 0.08 if sharp else 0.22}
            d = s['t1'] - s['t0']
            if a['kind'] == 'photo':
                w, h = a['w'], a['h']
                n = sum(1 for x in shots if x['asset'] == asset)
                if sharp and n % 3 != 2 and min(w, h) >= (700 if a.get('whole') and h >= w * 1.5 else 800):  # fills the screen, a slow move across the picture (a small picture is never blown up: it is shown whole)
                    z = max(1920 / h, 1080 / w) * 1.02
                    span = max(0.0, w - 1080 / z)
                    x0, x1 = (w / 2 - span * 0.42, w / 2 + span * 0.42) if n % 2 == 0 else (w / 2 + span * 0.42, w / 2 - span * 0.42)
                    s.update(mode='S', img='assets/' + a['file'], k0=[x0, h / 2, z], k1=[x1, h / 2, z * 1.05])
                else:                                          # the whole picture on its blurred copy
                    z = min(1080 / w, 1150 / h)
                    s.update(mode='S', img='assets/' + a['file'], k0=[w / 2, h / 2, z * 0.96], k1=[w / 2, h / 2, z], fy=700, dim=0.0 if sharp else 0.4)
            else:
                ss, x = sup.moment(asset, d)
                small = max(1080 / max(1, a['w']), 1920 / max(1, a['h'])) > 1.6      # filling the screen would blow it up past what it holds: a card instead
                whole = a.get('whole') or meme                 # a meme is never cropped: its sides and its text are the joke
                s.update(mode=('W' if whole else 'C' if small else 'F') if sharp else 'B', src='assets/' + a['file'], ss=round(ss, 2), x=x, w=a['w'], h=a['h'])
                if not sharp:
                    s['credit'] = ''
            shots.append(s)
    return shots, end


def cues(plan, tl):
    """Where the sound hits: taken from the graphics, so picture and sound land on the same word."""
    lines = {l['id']: l for l in tl['lines']}
    imp, pop, ding, riser, sect = [[0.0, 1.1, 0.7]], [], [], [], []
    moods = ['groove', 'full', 'tense']
    for i, pl in enumerate(plan['lines']):
        ln = lines[pl['id']]
        kinds = [o['k'] for o in pl['overlays']]
        mood = 'full' if i == 0 or pl['id'] == 'vote' else 'bright' if pl['id'] == 'site' else 'desk' if ('receipt' in kinds or 'quote' in kinds) else 'tense' if 'rows' in kinds else moods[i % 3]
        sect.append([round(max(0, ln['s'] - 0.08), 3) if i else 0.0, mood])
        for o in pl['overlays']:
            if o.get('drop'):
                continue
            t = wt(ln, o.get('on'))
            if o['k'] == 'stamp':
                imp.append([t, 1.5 if i == 0 else 1.0, 0.95 if i == 0 else 0.6])
                if i == 0 and t - ln['s'] > 1.2:
                    riser.append([round(max(0.3, t - 1.6), 3), t])
            elif o['k'] in ('chip', 'sub', 'tag', 'quote', 'receipt'):
                pop.append(t)
            elif o['k'] == 'rows':
                imp += [[wt(ln, r.get('on')), 0.8, 0.5] for r in o['rows']]
            elif o['k'] == 'blocks':
                imp += [[wt(ln, x.get('on')), 1.0, 0.6] for x in o['items']]
        if pl['id'] == 'vote':
            v = plan.get('vote') or {}
            for side in ('a', 'b'):
                word = (v.get(side) or {}).get('word', '')
                hits = [w['s'] - 0.05 for w in ln['words'] if norm(w['w']) == norm(word).split(' ')[0]]
                if hits:
                    imp.append([round(hits[0], 3), 0.9, 0.55])
                    ding.append([round(hits[-1], 3), 0.2])
            ding.append([wt(ln, 'Comment'), 0.4])
            riser.append([round(max(0, ln['s'] - 1.1), 3), round(ln['s'], 3)])
        if pl['id'] == 'site':
            imp.append([wt(ln, 'Gen'), 0.9, 0.5]); pop += [wt(ln, 'full'), wt(ln, 'every'), wt(ln, 'Link')]
    for l in tl['lines']:                                    # a clip that speaks: the music steps back, one soft cue as it starts
        if l.get('bite'):
            sect.append([round(l['bite']['at'] - 0.2, 3), 'desk']); pop.append(round(l['bite']['at'], 3))
    sect.sort(key=lambda x: x[0])
    imp = sorted([x for x in imp if x[0] >= 0], key=lambda x: x[0])
    return {'bpm': 120 + (len(plan['title']) % 3) * 4, 'transpose': len(plan['title']) % 5 - 2, 'sections': sect, 'impact': imp, 'pop': sorted(set(pop)), 'ding': ding, 'buzz': [], 'riser': riser, 'count': []}


def slang_shots(plan, tl):
    """The slang format has no footage: one drawn scene per script line."""
    lines = {l['id']: l for l in tl['lines']}
    end, ids = round(tl['total'] + 0.45, 3), [l['id'] for l in plan['lines']]
    shots = []
    for i, lid in enumerate(ids):
        t0 = 0.0 if i == 0 else round(max(0.0, lines[lid]['s'] - 0.08), 3)
        t1 = end if i == len(ids) - 1 else round(max(0.0, lines[ids[i + 1]]['s'] - 0.08), 3)
        shots.append({'id': lid + '_0', 'line': lid, 't0': t0, 't1': t1, 'mode': 'N', 'asset': 'drawn', 'credit': '', 'dim': 0.0})
    return shots, end


def cues_slang(plan, tl):
    L, S = {l['id']: l for l in tl['lines']}, plan['slang']
    start = lambda i: round(max(0.0, L[i]['s'] - 0.08), 3)
    last = lambda i, label: next((w['s'] - 0.05 for w in reversed(L[i]['words']) if norm(w['w']) == norm(label).split(' ')[0]), L[i]['e'] - 0.8)
    rev = L['rev']['words'][-1]['s'] - 0.04
    sect = [[0.0, 'tense'], [round(rev, 3), 'hits']] + [[start(i), mood] for i, mood in (('mean', 'groove'), ('forms', 'full'), ('round1', 'full'), ('origin', 'desk'), ('quote', 'tense'), ('final', 'full'), ('site', 'bright')) if i in L]
    imp, pop = [[0.0, 1.0, 0.6], [round(rev, 3), 1.6, 1.0]], [round(L['hook']['s'] + 0.55 + k * 0.28, 3) for k in range(3)]
    for k, it in enumerate(S['round']['items']):
        if 'round%d' % (k + 1) in L:
            imp.append([round(last('round%d' % (k + 1), S['round']['yes'] if it['is'] else S['round']['no']), 3), 1.1, 0.7])
    for i in ('mean', 'forms', 'origin', 'quote'):
        if i in L:
            pop.append(round(L[i]['s'] + 0.15, 3))
    ding = []
    if 'final' in L:
        for side in ('a', 'b'):
            imp.append([wt(L['final'], S['final'][side]['word']), 0.9, 0.55])
        ding.append([wt(L['final'], 'Comment'), 0.4])
    if 'site' in L:
        imp.append([wt(L['site'], 'Gen'), 0.9, 0.5]); pop += [wt(L['site'], 'timeline'), wt(L['site'], 'receipt'), wt(L['site'], 'Link')]
    gap = max(0.3, (rev - L['lock']['e']) / 3)
    return {'bpm': 122, 'transpose': len(S['word']) % 5 - 2, 'sections': sect, 'impact': sorted(imp), 'pop': sorted(set(pop)), 'ding': ding, 'buzz': [],
            'riser': [[round(L['lock']['s'], 3), round(rev, 3)]], 'count': [[round(L['lock']['e'] + 0.02, 3), 3, round(gap, 3)]]}


def fit(mode, w, h, x, luma=None):
    # A CLIP FILMED IN THE DARK IS LIFTED (measured in gather: the mean brightness of its pictures, 0 to 255): a little
    # noise taken off, then the shadows opened. A hand cut does this before anything else; unlifted, a night clip is a black screen.
    pre = 'hqdn3d=2:2:5:5,eq=gamma=%s:contrast=1.06:saturation=1.15,' % ('1.4' if luma < 45 else '1.2') if luma is not None and luma < 70 else ''
    if w * 16 > h * 9:                                         # wider than 9:16: a window of the picture
        win = pre + 'crop=ih*9/16:ih:(iw-ih*9/16)*%.3f:0,scale=1080:1920:flags=lanczos' % x
    else:
        win = pre + 'scale=1080:-2:flags=lanczos,crop=1080:1920'
    if mode == 'W':                                            # the WHOLE picture, as wide as the screen allows, on a blurred and darkened copy of itself
        k = min(1024 / w, 1150 / h)                            # a little narrower than the screen: the camera's slow push and its shakes never cut its edges
        fw, fh = int(w * k) // 2 * 2, int(h * k) // 2 * 2
        return ['-filter_complex', ('[0:v]fps=%d,' % FPS) + pre + 'split[a][b];[a]scale=1080:1920:force_original_aspect_ratio=increase,crop=1080:1920,gblur=sigma=28,eq=brightness=-0.2:saturation=0.9[bg];'
                '[b]scale=%d:%d:flags=lanczos,unsharp=5:5:0.4[fg];[bg][fg]overlay=%d:%d' % (fw, fh, (1080 - fw) // 2, max(120, 700 - fh // 2))]
    if mode == 'F':
        return ['-vf', 'fps=%d,%s,unsharp=5:5:0.5' % (FPS, win)]
    if mode == 'B':
        return ['-vf', 'fps=%d,%s,gblur=sigma=16,eq=brightness=-0.09:saturation=0.9' % (FPS, win)]
    return ['-filter_complex', ('[0:v]fps=%d,' % FPS) + pre + 'split[a][b];[a]crop=ih*9/16:ih:(iw-ih*9/16)*0.5:0,scale=1080:1920,gblur=sigma=26,eq=brightness=-0.2:saturation=0.85[bg];'
            '[b]crop=ih*4/5:ih:(iw-ih*4/5)*%.3f:0,scale=1080:-2:flags=lanczos,unsharp=5:5:0.7[fg];[bg][fg]overlay=0:240' % x]


def main(work, log=lambda *a: print(*a, flush=True)):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    tl = json.load(open(os.path.join(work, 'timeline.json'), encoding='utf-8'))
    slang = plan.get('format') == 'slang'
    shots, end = slang_shots(plan, tl) if slang else plan_shots(plan, tl, log)
    if not slang:                                              # what the cut took off (a name written for footage that is not there, the labels of two windows that became one
        json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)      # picture) and the time of every sentence stay with the plan: the layout repair reads it from there, and had put them back
    receipts = {}
    rdir = os.path.join(work, 'receipts')
    for f in sorted(os.listdir(rdir)) if os.path.isdir(rdir) else []:
        if f.endswith('.json'):
            receipts[f[:-5]] = json.load(open(os.path.join(rdir, f), encoding='utf-8'))
    base = os.path.join(work, 'base')
    shutil.rmtree(base, ignore_errors=True)
    for s in shots:
        s['n'] = int(round((s['t1'] - s['t0']) * FPS)) + 3
        s['dir'] = s['id']
        if s['mode'] in 'SDN':
            continue
        folder = os.path.join(base, s['id']); os.makedirs(folder)
        d = s['t1'] - s['t0']
        cmd = ['ffmpeg', '-y', '-v', 'error', '-ss', '%.3f' % s['ss'], '-t', '%.3f' % (d + 0.6), '-i', os.path.join(work, s['src'])] + fit(s['mode'], s['w'], s['h'], s['x'], (plan.get('assets') or {}).get(s['asset'], {}).get('luma')) + ['-frames:v', str(s['n']), '-q:v', '3', os.path.join(folder, '%04d.jpg')]
        subprocess.run(cmd, check=True, timeout=240)
        got = len(os.listdir(folder))
        if got == 0:
            raise SystemExit('no frames for shot %s (%s at %.1fs)' % (s['id'], s['asset'], s['ss']))
        for k in range(got + 1, s['n'] + 1):                   # a clip that ends early holds its last picture
            shutil.copy(os.path.join(folder, '%04d.jpg' % got), os.path.join(folder, '%04d.jpg' % k))
    sfile = os.path.join(work, 'site', 'site.json')
    comp = {'fps': FPS, 'end': end, 'frames': int(round(end * FPS)), 'shots': shots, 'lines': tl['lines'], 'cues': cues_slang(plan, tl) if slang else cues(plan, tl), 'receipts': receipts,
            'site': json.load(open(sfile, encoding='utf-8')) if os.path.isfile(sfile) else None,
            'plan': {k: plan.get(k) for k in ('lines', 'vote', 'title', 'url', 'format', 'slang')}, 'brand': {k: brand.B[k] for k in ('site', 'spoken', 'shown', 'name', 'css')}}
    json.dump(comp, open(os.path.join(work, 'comp.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    open(os.path.join(work, 'comp.js'), 'w', encoding='utf-8').write('window.COMP = ' + json.dumps(comp, ensure_ascii=False) + ';')
    film = sum(s['t1'] - s['t0'] for s in shots)
    log('cut: %d shots over %.1f s, footage under %.0f%% of it | sharp %d, blurred %d, pictures %d | assets used: %s' % (
        len(shots), end, 100 * film / end, sum(s['mode'] in 'FCW' for s in shots), sum(s['mode'] == 'B' for s in shots), sum(s['mode'] in 'SD' for s in shots), ', '.join(sorted({s['asset'] for s in shots}))))
    return comp


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
