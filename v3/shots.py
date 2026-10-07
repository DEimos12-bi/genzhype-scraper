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
        self.a = {k: v for k, v in assets.items() if v.get('file')}
        self.used = {k: [] for k in self.a}
        self.turn = 0

    def clips(self, sharp):
        out = [k for k, v in self.a.items() if v['kind'] in ('clip', 'hunt', 'stock') and (not sharp or (v['kind'] != 'stock' and v.get('usable', True)))]
        return sorted(out, key=lambda k: (not self.a[k].get('usable', True), not self.a[k].get('eyes', {}).get('exact', True), len(self.used[k]), -len(self.a[k].get('windows', []))))

    def photos(self):
        return [k for k, v in self.a.items() if v['kind'] == 'photo']

    def moment(self, k, dur):
        v = self.a[k]
        room = max(0.0, v.get('dur', 0) - dur - 0.3)
        for w in v.get('windows', []):
            s = min(room, max(0.0, w['t'] - 0.8))
            if all(abs(s - u) >= min(3.5, dur) for u in self.used[k]):
                self.used[k].append(s)
                return s, w.get('x', 0.5)
        n = len(self.used[k])                                  # every good moment used: walk through the clip
        s = (n * 7.3 + 1.5) % room if room > 0 else 0.0
        self.used[k].append(s)
        return s, 0.5


def plan_shots(plan, tl, log):
    lines = {l['id']: l for l in tl['lines']}
    end = round(tl['total'] + 0.45, 3)
    sup = Supply(plan['assets'])
    if not sup.a:
        raise SystemExit('NO FOOTAGE: nothing was fetched for this story; no video is made')
    people = [norm(p.get('name', '')) for p in plan.get('people', []) if p.get('name')]
    shots, ids = [], [l['id'] for l in plan['lines']]
    for i, pl in enumerate(plan['lines']):
        ln = lines[pl['id']]
        t0 = 0.0 if i == 0 else round(max(0.0, ln['s'] - 0.08), 3)
        t1 = end if i == len(ids) - 1 else round(max(0.0, lines[ids[i + 1]]['s'] - 0.08), 3)
        want, sharp = pl['show']['asset'], pl['show']['mode'] == 'sharp'
        if i == 0:
            sharp = True                                       # the first second is always real footage, sharp
        asset = want if want in sup.a else None
        if asset and sharp and sup.a[asset]['kind'] != 'photo' and (sup.a[asset]['kind'] == 'stock' or not sup.a[asset].get('usable', True)):
            asset = None                                       # refused by the eyes: not shown sharp
        if not asset:
            # the footage this line asked for is not there. Other footage may stand in SHARP only if its own title or post
            # names something the line names (never the wrong game or the wrong event under the words); else it goes blurred.
            said = {w.lower() for w in re.findall(r'\b[A-Z][A-Za-z0-9À-ÿ]{2,}\b', pl['text'])} - {'the', 'this', 'that', 'they', 'their', 'then', 'when', 'what', 'will', 'with', 'and', 'but', 'now', 'its', 'one', 'some', 'same'}
            fits = [k for k in sup.clips(True) + sup.photos() if said & set(norm(sup.a[k].get('title', '') if sup.a[k]['kind'] == 'hunt' else sup.a[k].get('about', '')).split())]
            if sharp and want != 'auto' and fits and i > 0 and plan['assets'].get(want, {}).get('kind') != 'hunt':
                asset = fits[0]                                # a post's own clip or picture may stand in for another of the story's posts
            elif sharp and want != 'auto':                     # a searched clip that is missing has no stand-in: the wrong game under the words is worse than a blur
                if i == 0:
                    raise SystemExit('NO FOOTAGE OF THE SUBJECT: the opening asked for "%s" (%s) and nothing fetched shows what the first line names' % (want, plan['assets'].get(want, {}).get('about', '')[:60]))
                sharp = False
                log('  %s: its footage (%s) is missing and nothing else names what the line names; other footage goes blurred' % (pl['id'], want))
            if not asset:
                pool = sup.clips(sharp) or sup.photos() or sup.clips(False)
                asset = pool[0]
            if sup.a[asset]['kind'] == 'stock' or not sup.a[asset].get('usable', True):
                sharp = False
        a = sup.a[asset]
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
        cuts = [t0] + split_times(ln, t0, t1, parts) + [t1]
        for k in range(len(cuts) - 1):
            s = {'id': '%s_%d' % (pl['id'], k), 'line': pl['id'], 't0': cuts[k], 't1': cuts[k + 1], 'asset': asset, 'credit': a.get('credit', ''), 'dim': 0.08 if sharp else 0.28}
            d = s['t1'] - s['t0']
            if a['kind'] == 'photo':
                w, h = a['w'], a['h']
                n = sum(1 for x in shots if x['asset'] == asset)
                if sharp and n % 3 != 2:                       # fills the screen, a slow move across the picture
                    z = max(1920 / h, 1080 / w) * 1.02
                    span = max(0.0, w - 1080 / z)
                    x0, x1 = (w / 2 - span * 0.42, w / 2 + span * 0.42) if n % 2 == 0 else (w / 2 + span * 0.42, w / 2 - span * 0.42)
                    s.update(mode='S', img='assets/' + a['file'], k0=[x0, h / 2, z], k1=[x1, h / 2, z * 1.05])
                else:                                          # the whole picture on its blurred copy
                    z = min(1080 / w, 1150 / h)
                    s.update(mode='S', img='assets/' + a['file'], k0=[w / 2, h / 2, z * 0.96], k1=[w / 2, h / 2, z], fy=700, dim=0.0 if sharp else 0.4)
            else:
                ss, x = sup.moment(asset, d)
                small = a['w'] > a['h'] and a['h'] < 700
                s.update(mode=('C' if small else 'F') if sharp else 'B', src='assets/' + a['file'], ss=round(ss, 2), x=x, w=a['w'], h=a['h'])
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
    imp = sorted([x for x in imp if x[0] >= 0], key=lambda x: x[0])
    return {'bpm': 120 + (len(plan['title']) % 3) * 4, 'transpose': len(plan['title']) % 5 - 2, 'sections': sect, 'impact': imp, 'pop': sorted(set(pop)), 'ding': ding, 'buzz': [], 'riser': riser, 'count': []}


def fit(mode, w, h, x):
    if w * 16 > h * 9:                                         # wider than 9:16: a window of the picture
        win = 'crop=ih*9/16:ih:(iw-ih*9/16)*%.3f:0,scale=1080:1920:flags=lanczos' % x
    else:
        win = 'scale=1080:-2:flags=lanczos,crop=1080:1920'
    if mode == 'F':
        return ['-vf', 'fps=%d,%s,unsharp=5:5:0.5' % (FPS, win)]
    if mode == 'B':
        return ['-vf', 'fps=%d,%s,gblur=sigma=16,eq=brightness=-0.16:saturation=0.9' % (FPS, win)]
    return ['-filter_complex', '[0:v]fps=%d,split[a][b];[a]crop=ih*9/16:ih:(iw-ih*9/16)*0.5:0,scale=1080:1920,gblur=sigma=26,eq=brightness=-0.2:saturation=0.85[bg];'
            '[b]crop=ih*4/5:ih:(iw-ih*4/5)*%.3f:0,scale=1080:-2:flags=lanczos,unsharp=5:5:0.5[fg];[bg][fg]overlay=0:240' % (FPS, x)]


def main(work, log=lambda *a: print(*a, flush=True)):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    tl = json.load(open(os.path.join(work, 'timeline.json'), encoding='utf-8'))
    shots, end = plan_shots(plan, tl, log)
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
        if s['mode'] == 'S':
            continue
        folder = os.path.join(base, s['id']); os.makedirs(folder)
        d = s['t1'] - s['t0']
        cmd = ['ffmpeg', '-y', '-v', 'error', '-ss', '%.3f' % s['ss'], '-t', '%.3f' % (d + 0.6), '-i', os.path.join(work, s['src'])] + fit(s['mode'], s['w'], s['h'], s['x']) + ['-frames:v', str(s['n']), '-q:v', '3', os.path.join(folder, '%04d.jpg')]
        subprocess.run(cmd, check=True, timeout=240)
        got = len(os.listdir(folder))
        if got == 0:
            raise SystemExit('no frames for shot %s (%s at %.1fs)' % (s['id'], s['asset'], s['ss']))
        for k in range(got + 1, s['n'] + 1):                   # a clip that ends early holds its last picture
            shutil.copy(os.path.join(folder, '%04d.jpg' % got), os.path.join(folder, '%04d.jpg' % k))
    comp = {'fps': FPS, 'end': end, 'frames': int(round(end * FPS)), 'shots': shots, 'lines': tl['lines'], 'cues': cues(plan, tl), 'receipts': receipts,
            'plan': {k: plan.get(k) for k in ('lines', 'vote', 'title', 'url')}, 'brand': json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'config.json'), encoding='utf-8'))['brand']}
    json.dump(comp, open(os.path.join(work, 'comp.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    open(os.path.join(work, 'comp.js'), 'w', encoding='utf-8').write('window.COMP = ' + json.dumps(comp, ensure_ascii=False) + ';')
    film = sum(s['t1'] - s['t0'] for s in shots)
    log('cut: %d shots over %.1f s, footage under %.0f%% of it | sharp %d, blurred %d, pictures %d | assets used: %s' % (
        len(shots), end, 100 * film / end, sum(s['mode'] in 'FC' for s in shots), sum(s['mode'] == 'B' for s in shots), sum(s['mode'] == 'S' for s in shots), ', '.join(sorted({s['asset'] for s in shots}))))
    return comp


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
