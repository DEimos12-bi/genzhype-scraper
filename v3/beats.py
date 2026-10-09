"""THE MONTAGE, SENTENCE BY SENTENCE: the way a person cuts these videos by hand.
Every sentence of the script is a BEAT with its own picture, chosen for what that sentence names, and cut on its
first spoken word:
  close   a still, close on ONE of its subjects (the picture model said where each one is), slowly pushing in
  pan     a still moving from one subject to another
  two     two subjects, one above the other, each in its own window ("both", "each side", "versus")
  whole   the whole still
  play    a moving clip, sharp
  under   the picture blurred behind a big graphic or a card
A beat too short to read (under 0.8 s) shares the picture of the next one; one too long gets a second framing.
The plan's graphics belong to a beat as well: they leave with its picture (build.js)."""
import math
import re

MIN_BEAT, SHARP_MAX, UNDER_MAX = 0.8, 4.6, 6.0
MOVES = ('close', 'pan', 'two', 'whole', 'play', 'under')


def split_keep(text):
    """A line as its sentences with nothing lost: joined with a space they give the line back."""
    return [x.strip() for x in re.findall(r'.+?(?:[.!?…]+["”’\']*(?=\s|$)|$)', (text or '').strip(), flags=re.S) if x.strip()]


def squash(text):
    return re.sub(r'[^a-z0-9]', '', (text or '').lower())


def starts(words, sentences):
    """The index of the first spoken word of each sentence (the voice gives one time per word)."""
    out, i, n = [], 0, len(words)
    for sent in sentences:
        out.append(min(i, max(0, n - 1)))
        want, got = len(squash(sent)), 0
        while i < n and got < want:
            got += len(squash(words[i]['w']))
            i += 1
    return out


def subject(asset, sid):
    for s in asset.get('subjects') or []:
        if s.get('id') == sid:
            return s
    return None


def _inside(c, half, size):
    """A centre kept far enough from the edges for the view to stay inside the picture."""
    return size / 2 if size <= 2 * half else min(size - half, max(half, c))


def close_on(a, s, fy=960, tighter=1.0):
    """The framing of a still close on one subject: [centre x, centre y, zoom], before and after a slow push."""
    w, h = a['w'], a['h']
    fill = max(1080 / w, 1920 / h)
    z = min(fill * 2.7, max(fill * 1.08, 0.62 * 1920 / (max(0.14, float(s.get('h', 0.5))) * h))) * tighter
    out = []
    for zz in (z, z * 1.1):
        cx = _inside(float(s['x']) * w, 540 / zz, w)
        top, bottom = fy / zz, (1920 - fy) / zz
        cy = h / 2 if h <= top + bottom else min(h - bottom, max(top, float(s['y']) * h))
        out.append([round(cx, 1), round(cy, 1), round(zz, 4)])
    return out


def window_on(a, s):
    """One of the two stacked windows (1080 x 560): the subject fills it."""
    w, h = a['w'], a['h']
    fill = max(1080 / w, 560 / h)
    z = min(fill * 3.2, max(fill, 0.86 * 560 / (max(0.1, float(s.get('h', 0.4))) * h)))
    out = []
    for zz in (z, z * 1.1):
        out.append([round(_inside(float(s['x']) * w, 540 / zz, w), 1), round(_inside(float(s['y']) * h, 280 / zz, h), 1), round(zz, 4)])
    return {'k0': out[0], 'k1': out[1]}


def whole_still(a, nth, sharp=True):
    w, h = a['w'], a['h']
    if sharp and (h >= w * 1.3 or nth % 3 != 2) and min(w, h) >= (700 if h >= w * 1.3 else 800):      # fills the screen, a slow move across it (an upright picture always does)
        z = max(1920 / h, 1080 / w) * 1.02
        span = max(0.0, w - 1080 / z)
        x0, x1 = (w / 2 - span * 0.42, w / 2 + span * 0.42) if nth % 2 == 0 else (w / 2 + span * 0.42, w / 2 - span * 0.42)
        return dict(k0=[x0, h / 2, z], k1=[x1, h / 2, z * 1.05])
    z = min(1080 / w, 1150 / h)                                # a small picture is never blown up: shown whole on its blurred copy
    return dict(k0=[w / 2, h / 2, z * 0.96], k1=[w / 2, h / 2, z], fy=700, dim=0.0 if sharp else 0.4)


def for_line(pl, ln, t0, t1, sup, shots, pick, has_card, card_to, face_ok, log, first=False, single=False):
    """The shots of one line that has beats. pick(last asset) gives a picture when a beat asked for none that exists;
    has_card(beat index) says whether a card (quote, rows, receipt, blocks) sits on that beat and card_to(beat index) the last
    beat it stays for (a lyric shown line by line runs over two sentences); face_ok(asset, words) says
    whether a clip of a person may be shown sharp under these words."""
    beats = pl['beats']
    idx = starts(ln['words'], [b['say'] for b in beats])
    times = [t0] + [round(max(t0, ln['words'][i]['s'] - 0.06), 3) for i in idx[1:]] + [t1]
    for n, bt in enumerate(beats):                             # kept with the plan: the graphics of a beat are timed inside it
        bt['t0'], bt['t1'] = times[n], times[n + 1]
    holds = lambda n: any(has_card(m) and card_to(m) > n for m in range(n + 1))
    groups, k = [], 0
    while k < len(beats):                                      # a beat too short to read shares the picture of the next one
        a, members = times[k], [k]
        while (times[k + 1] - a < MIN_BEAT or holds(k)) and k + 1 < len(beats):      # ...and a card that runs on into the next sentence keeps its picture
            k += 1
            members.append(k)
        groups.append([a, times[k + 1], members])
        k += 1
    if len(groups) > 1 and groups[-1][1] - groups[-1][0] < MIN_BEAT:
        last = groups.pop()
        groups[-1][1], groups[-1][2] = last[1], groups[-1][2] + last[2]
    if single:                                                 # the vote, the closing: one picture under the machine's own graphics
        groups = [[t0, t1, list(range(len(beats)))]]
    out = []
    for a, b, members in groups:
        lead = max(members, key=lambda m: times[m + 1] - times[m])      # the longest sentence of the group chooses the picture
        show = dict(beats[lead].get('show') or {})
        card = any(has_card(m) for m in members)
        prev = (out or shots)[-1]['asset'] if (out or shots) else None
        asset = show.get('asset') if show.get('asset') in sup.a and sup.a[show['asset']].get('usable', True) else None
        if not asset:
            asset = pick(prev)
            show = {'asset': asset, 'move': 'auto'}
            named = [o for o in pl.get('overlays', []) if o.get('beat') in members and o.get('k') in ('name', 'tag', 'labels')]
            if named:                                          # they named the picture that was asked for, not the one that plays instead
                pl['overlays'] = [o for o in pl['overlays'] if o not in named]
                log('  %s: the picture asked for is not there; "%s" is not written on the one that plays instead' % (pl['id'], str(named[0].get('t') or named[0].get('name') or named[0].get('a'))[:30]))
        A = sup.a[asset]
        still = A['kind'] == 'photo'
        move = show.get('move') if show.get('move') in MOVES else 'auto'
        if A['kind'] == 'stock' and not (first and not out):
            move = 'under'                                     # neutral stock footage is only ever a blurred background
        if not still and move in ('close', 'pan', 'two', 'whole', 'auto'):
            move = 'under' if card else 'play'
        if still and move in ('play', 'auto'):
            move = 'close' if subject(A, show.get('focus')) else 'whole'
        if still and move in ('close', 'pan', 'two') and not subject(A, show.get('focus')):
            move = 'whole'
        if move in ('pan', 'two') and (not subject(A, show.get('to')) or show.get('to') == show.get('focus')):
            move = 'close'
        if move == 'play' and not face_ok(asset, pl['text']):
            move = 'under'
            log('  %s: %s shows a person these words do not name; shown blurred' % (pl['id'], asset))
        if single:
            move = 'whole' if still else 'under'
        if first and not out and move == 'under':
            move = 'close' if still and subject(A, show.get('focus')) else 'whole' if still else 'play'      # the first second is always sharp
        same_as_last = out and out[-1]['asset'] == asset and out[-1].get('move') == move and out[-1].get('focus') == show.get('focus') and out[-1].get('card') == card             and move in ('play', 'under', 'close', 'whole') and b - out[-1]['t0'] <= (UNDER_MAX if move == 'under' else SHARP_MAX) + 1.2
        if same_as_last:                                       # the same picture, framed the same way: it plays on, it does not jump
            out[-1]['t1'] = b
            out[-1]['beats'] = out[-1]['beats'] + members
            continue
        sup.uses[asset] += 1
        parts = 1 if single else max(1, math.ceil((b - a) / (UNDER_MAX if move == 'under' else SHARP_MAX)))
        for p in range(parts):
            s0, s1 = round(a + (b - a) * p / parts, 3), round(a + (b - a) * (p + 1) / parts, 3)
            s = {'id': '%s_%d' % (pl['id'], len(out)), 'line': pl['id'], 'beats': members, 't0': s0, 't1': s1, 'asset': asset, 'credit': A.get('credit', ''), 'dim': 0.08, 'move': move, 'focus': show.get('focus'), 'card': card}
            if still:
                img, same = 'assets/' + A['file'], sum(1 for x in shots + out if x['asset'] == asset)
                fy = 560 if card else 960                      # a card sits low on a sharp picture: the subject is kept above it
                m = move if p == 0 else ('whole' if move in ('close', 'pan') else 'close')      # a long sentence: a second framing
                if m == 'two':
                    s.update(mode='D', img=img, a=window_on(A, subject(A, show['focus'])), b=window_on(A, subject(A, show['to'])), dim=0.0)
                elif m == 'pan':
                    k0, k1 = close_on(A, subject(A, show['focus']), fy, 0.9)[0], close_on(A, subject(A, show['to']), fy, 0.9)[0]
                    k1[2] = k0[2]
                    s.update(mode='S', img=img, k0=k0, k1=k1, fy=fy)
                elif m == 'close' and subject(A, show.get('focus')):
                    k0, k1 = close_on(A, subject(A, show['focus']), fy, 0.82 if card else 1.0)
                    s.update(mode='S', img=img, k0=k0, k1=k1, fy=fy)
                elif m == 'under':
                    s.update(mode='S', img=img, **whole_still(A, same, sharp=False))
                    s['credit'] = ''
                else:
                    s.update(mode='S', img=img, **whole_still(A, same))
                    if single:
                        s['dim'] = 0.5
                if card and s['mode'] == 'S' and m != 'under':
                    s['dim'] = max(s.get('dim', 0.0), 0.14)
            else:
                ss, x = sup.moment(asset, s1 - s0)
                tall = A['h'] >= A['w'] * 1.3
                small = A['w'] > A['h'] and A['h'] < 700
                if move == 'under':
                    s.update(mode='B', dim=0.3, credit='')
                else:
                    s.update(mode='F' if tall else 'W' if (A.get('whole') or small) else 'F')
                s.update(src='assets/' + A['file'], ss=round(ss, 2), x=x, w=A['w'], h=A['h'])
            out.append(s)
    return out
