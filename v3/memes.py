"""A MEME'S OWN PICTURES. A meme page links the meme itself: GIPHY GIFs, TikTok and YouTube posts that use it. They are
fetched and LOOKED AT before the script is written, so that the director writes to pictures it knows, and the video
shows the meme from the first second to the last.
  collect(m, work)   every example into <work>/assets: a GIF as a short looping clip, a post as its preview picture
                     (the public embed data of TikTok and YouTube: no login, nothing is worked around);
  look(assets, m, work)   each one goes to the picture model on its own: what it shows, whether it shows THIS meme, and
                     whether a real person is its subject. An example that shows something else is not used (a page's
                     list of examples is not always clean), nor is one that nobody looked at.
usage: memes.py <work folder>     (prints what was fetched and seen; the director does this by itself)"""
import json
import math
import os
import re
import subprocess
import sys

import ai
import footage
import supply

COMMON = set('explained memes trend trends viral meaning origin where which about video videos games gaming internet tiktok challenge sound dance people their there these those while after '
             'before being every other first funny really thing things youtube twitter reddit online social media story'.split())      # title words that name no meme
HUMAN = re.compile(r'\b(man|woman|boy|girl|guy|person|people|teen\w*|kids?|child|children|lady|men|women|player|student|someone)\b', re.I)

PREVIEWS = ('https://i.ytimg.com/vi/%s/oar2.jpg', 'https://i.ytimg.com/vi/%s/maxresdefault.jpg', 'https://i.ytimg.com/vi/%s/hqdefault.jpg')      # a Short's upright picture first


def collect(m, work, log=print):
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    out = {}
    for i, ex in enumerate((m.get('examples') or [])[:12]):
        aid = 'meme%d' % i
        a = {'whole': True, 'found': ex.get('found', ''), 'from': ex['kind'], 'by': ex.get('by', ''), 'page': ex.get('page', ''), 'title': ex.get('title', ''), 'date': ex.get('date', ''), 'about': ex.get('title', '')[:240]}
        who = ''.join(c for c in ex.get('by', '').upper() if c.isalnum() or c in '@._- ')[:24].strip()
        try:
            if ex['kind'] == 'gif':
                raw, dest = os.path.join(adir, aid + '.gif.mp4'), os.path.join(adir, aid + '.mp4')
                footage.save(ex['video'], raw, timeout=40)
                w, h, dur = footage.probe(raw)
                if not w or dur <= 0:
                    raise ValueError('unreadable GIF')
                loops = max(0, int(math.ceil(13.0 / dur)) - 1)   # a GIF lasts a second or two: it is looped, so a shot can start at different points of it
                # an upright GIF is small (270 px wide is common): scaled up ONCE, cleaned and sharpened, so that it can fill the screen
                size = 'hqdn3d=1.5:1:3:3,scale=1080:-2:flags=spline,unsharp=7:7:0.9:5:5:0.0' if h >= w * 1.3 and w < 1000 else 'scale=trunc(iw/2)*2:trunc(ih/2)*2'
                footage.run(['ffmpeg', '-y', '-v', 'error', '-stream_loop', str(loops), '-i', raw, '-t', '14', '-an', '-vf', 'fps=30,' + size,
                             '-c:v', 'libx264', '-crf', '16', '-pix_fmt', 'yuv420p', dest], 100)
                os.remove(raw)
                a.update(kind='clip', file=aid + '.mp4', credit='GIF · %s / GIPHY' % who if who and who != 'GIPHY' else 'GIF · GIPHY', loop=round(dur, 2))
                a['w'], a['h'], a['dur'] = footage.probe(dest)
            else:
                if ex['kind'] == 'tiktok':                     # the post's own VIDEO by the site's TikTok route (supply.py); its cover picture only when that is refused
                    meta = supply.fetch(ex['page'], os.path.join(adir, aid + '.mp4'), keep=30, log=lambda s: None)
                    if meta:
                        a.update(kind='clip', file=aid + '.mp4', credit='TIKTOK · %s' % who, w=meta['w'], h=meta['h'], dur=meta['dur'], plays=meta.get('plays', 0))
                        if meta.get('title') and not a.get('title'):
                            a['title'] = a['about'] = meta['title'][:240]
                        out[aid] = a
                        continue
                raw, dest = os.path.join(adir, aid + '.raw'), os.path.join(adir, aid + '.jpg')
                for u in ([ex['image']] if ex.get('image') else [p % ex['id'] for p in PREVIEWS]):
                    try:
                        if footage.save(u, raw, timeout=25) > 4000:
                            break
                    except Exception:  # noqa: BLE001
                        continue
                footage.run(['ffmpeg', '-y', '-v', 'error', '-i', raw, '-frames:v', '1', '-q:v', '2', dest], 40)      # whatever the format, one JPEG
                if os.path.exists(raw):
                    os.remove(raw)
                a.update(kind='photo', file=aid + '.jpg', credit='%s · %s' % ('TIKTOK' if ex['kind'] == 'tiktok' else 'YOUTUBE', who))
                a['w'], a['h'] = footage.probe(dest)[:2]
                a['dur'] = 0.0
            if not a.get('w'):
                raise ValueError('unreadable file')
        except Exception as e:  # noqa: BLE001
            log('  %s (%s by %s): not fetched (%s)' % (aid, ex['kind'], ex.get('by', '?'), str(e)[:70]))
            continue
        out[aid] = a
    log('meme pictures: %d of %d examples fetched (%s)' % (len(out), len(m.get('examples') or []), ', '.join('%s %s %dx%d' % (k, a['from'], a['w'], a['h']) for k, a in out.items()) or 'none'))
    return out


def look(assets, m, work, log=print, tick=None):
    """Every example is looked at on its own (on a shared sheet the picture model mixes pictures up). Sets "seen" (what
    it shows), "usable" and, for a GIF, what the cut needs. tick() is called after each one: the caller keeps the
    progress there, and may stop the run at its time budget (the next run goes on with the next picture)."""
    edir = os.path.join(work, 'eyes'); os.makedirs(edir, exist_ok=True)
    for k, a in assets.items():
        if 'looked' in a:
            continue
        tile, t, model = os.path.join(edir, k + '.jpg'), None, 'none'
        subprocess.run(['ffmpeg', '-y', '-v', 'error'] + (['-ss', '%.2f' % (a.get('loop', 1.0) * 0.45)] if a['kind'] == 'clip' else []) + ['-i', os.path.join(work, 'assets', a['file']), '-frames:v', '1',
                        '-vf', 'scale=768:768:force_original_aspect_ratio=decrease', '-q:v', '3', tile], timeout=40)
        # The picture is described BLIND: told what the meme is, a weak picture model repeats that for pictures that show something else.
        user = ('Describe this ONE picture for someone who cannot see it. Only what is VISIBLE: do not guess what it is from, do not guess names.\n'
                '"what": what it shows, in at most 24 words. Be exact: the figures, their colours and shapes, what each one is DOING ("a brown dog jumps over a low wooden fence");\n'
                '"text": the words written in the picture, exactly as written (at most 12 words; "" if there are none);\n'
                '"photo": true if it is a photograph or a frame of real-world video, false if it is an animation, a cartoon, a drawing, a video game or a 3D render;\n'
                '"person": "real" if a real human being (photographed or filmed) is the main subject, "drawn" if its figures are drawn, animated, 3D-rendered or game characters, "none" if nobody is in it;\n'
                '"score": 0 to 5, how clear and striking it is as a picture (0 = black, blank or unreadable);\n'
                '"caption": where words are written ACROSS the picture (a caption, a subtitle, a title laid over the scene): "top", "middle" or "bottom"; "none" when there are none '
                '(a small watermark, an account name or a logo in a corner does not count);\n'
                '"subjects": EVERY separate figure or thing in it that a camera could go close on, from LEFT to RIGHT, at most 6. Leave none out because it is small, at the edge, '
                'or drawn in another style than the others. For each: "name" (what it is by its look, 3 to 7 words: "woman in a red raincoat"), "x" and "y" (where its CENTRE is, as numbers from 0 to 1: x 0 = left edge, 1 = right edge; y 0 = top, 1 = bottom), '
                '"h" (how much of the picture\'s height it takes, from 0 to 1).\n'
                'JSON: {"what":"","text":"","photo":false,"person":"none","score":3,"caption":"none","subjects":[{"name":"","x":0.5,"y":0.5,"h":0.4}]}')
        try:
            t, model = ai.ask_json('You look at ONE picture. Answer with strict JSON only.', user, images=[tile], tries=3, temperature=0.1, timeout=60, max_tokens=1100)
        except ai.OutOfTime:
            raise                                              # the caller's time limit: this picture is looked at in the next run
        except Exception as e:  # noqa: BLE001
            log('  %s: no picture model answered (%s)' % (k, str(e)[-80:]))
        try:
            score = float((t or {}).get('score', 3))
        except (TypeError, ValueError):
            score = 3.0
        what, person = str((t or {}).get('what', '')).strip()[:170], str((t or {}).get('person', '')).lower()
        a['looked'], a['score'] = model, score
        subjects = []                                          # where each thing is, so that the cut can go close on the one a sentence names
        for sb in ((t or {}).get('subjects') or []) if a['kind'] == 'photo' else []:
            try:
                x, y, hh = float(sb.get('x')), float(sb.get('y')), float(sb.get('h') or 0.4)
            except (TypeError, ValueError, AttributeError):
                continue
            if 0 <= x <= 1 and 0 <= y <= 1 and str(sb.get('name') or '').strip():
                subjects.append({'id': 's%d' % (len(subjects) + 1), 'name': str(sb['name']).strip()[:60], 'x': round(x, 3), 'y': round(y, 3), 'h': round(min(1.0, max(0.08, hh)), 3)})
        a['subjects'] = subjects[:6]
        a['seen'] = (what + (' (text in it: "%s")' % str(t['text']).strip()[:80] if str(t.get('text') or '').strip() else '')) if t else ''
        # what a close-up must know about a still: where words lie across it (the cut never goes close into them) and how
        # light it is (of two pictures of the same figure, the clearer one is used)
        cap = str((t or {}).get('caption') or '').strip().lower()
        a['caption'] = cap if a['kind'] == 'photo' and cap in ('top', 'middle', 'bottom') else ''
        a['light'] = brightness(tile) if a['kind'] == 'photo' else None
        a['usable'], why = bool(t) and score >= 1, 'UNREADABLE' if t else 'NOBODY LOOKED AT IT'
        # A real person as the subject: a GIF of a stranger turned into a joke is never shown; somebody's own post only when
        # the owner allows it ("memes": {"real_people": true} in config.json). Nobody watches these videos before they exist,
        # which is also why a picture that no model looked at is not used. A description that names a human being counts as
        # a real person unless the model says the figures are drawn. An animation, a game or a drawing shows no real person:
        # "two men" in a cartoon are its characters (that threw the meme's own animation out).
        drawn = (t or {}).get('photo') is False or str((t or {}).get('photo')).lower() == 'false'
        real = not drawn and (person.startswith('real') or (not person.startswith('drawn') and bool(HUMAN.search(what))))
        a['person'] = 'real' if real else 'drawn' if drawn or person.startswith('drawn') else 'none'
        if a['usable'] and a['person'] == 'real' and (a['from'] == 'gif' or not ai.CONFIG.get('memes', {}).get('real_people')):
            a['usable'], why = False, 'A REAL PERSON IS ITS SUBJECT'
        if a['kind'] == 'clip':                                # a GIF needs no second look by the eyes step
            a['eyes'] = {'kind': 'meme', 'relevant': a['usable'], 'exact': a['usable'], 'shows': a['seen'][:160], 'model': model}
            a['windows'] = [{'t': 0.0, 'score': 4.0, 'x': 0.5, 'face': False, 'what': a['seen'][:70]}] if a['usable'] else []
        log('  %s (%s by %s): %s%s' % (k, a['from'], a['by'], a['seen'] or 'not looked at', '' if a['usable'] else '  -> %s: not used' % why))
        if tick:
            tick()
    return assets


def brightness(path):
    """How light a picture is, from 0 (black) to 1 (white): the mean of a small grey copy. None when it cannot be read."""
    try:
        r = subprocess.run(['ffmpeg', '-v', 'error', '-i', path, '-frames:v', '1', '-vf', 'scale=48:48,format=gray', '-f', 'rawvideo', '-'], capture_output=True, timeout=30)
        return round(sum(r.stdout) / (255.0 * len(r.stdout)), 3) if r.stdout else None
    except Exception:  # noqa: BLE001
        return None


def sort_topic(assets, m, log=print):
    """Which examples show THIS meme: decided by a text reader from the blind descriptions and the words posted with each
    one (a page's list of examples is not always clean). Without a reader they all stay."""
    # Only what the SEARCH brought is sorted here. What the page itself links as its examples is the page's choice: a text
    # reader that only has a blind description threw out the meme's own animation twice ("not about it").
    cand = {k: a for k, a in assets.items() if a.get('usable') and a.get('found')}
    if not cand:
        log('meme pictures: %d of %d will be shown (the page\'s own examples; the search brought nothing to sort)' % (sum(1 for a in assets.values() if a.get('usable')), len(assets)))
        return assets
    kinds = {'gif': 'a GIF', 'tiktok': 'a TikTok post', 'youtube': 'a YouTube video'}
    rows = '\n'.join('- %s (%s by %s%s): the picture shows: %s' % (k, kinds.get(a['from'], 'a picture'), a.get('by') or '?', ', posted with the words "%s"' % a['title'][:140] if a.get('title') else '', a.get('seen') or '?') for k, a in cand.items())
    about = '\n'.join([m.get('page_text', '')[:2600]] + [x.get('excerpt', '')[:1500] for x in (m.get('sources') or [])[:1]])
    user = ('THE MEME: %s\nWHAT THE PAGE AND ITS FIRST SOURCE SAY ABOUT IT (where it comes from, who is in it, how people use it):\n%s\n\n'
            'A search for the meme brought these moving GIFs. A picture model, which was NOT told what the meme is and cannot name its characters, wrote what each one shows.\n%s\n\n'
            'These come from a search, so some are about something else. One belongs to this meme when its picture or its words FIT what is described above: the meme itself, its characters '
            '(described only by shape and colour), its scene, the earlier video or song it grew out of, a remix, a template version, or when its words name it.\n'
            'One whose picture and words fit NOTHING described above goes in "off": say what it shows instead.\n'
            'JSON: {"on_topic":["meme0"],"off":[{"id":"meme9","why":"it is about ..., max 8 words"}]}' % (m.get('title', ''), about, rows))
    try:
        j, model = ai.ask_json('You sort the pictures of a short video about one internet meme. Strict JSON only.', user, kind='text', temperature=0.1, timeout=80, max_tokens=4000)      # a judgement: the writing models (the light ones change their mind from one try to the next)
    except ai.AIError as e:
        log('  no reader sorted the examples (%s): all %d stay' % (str(e)[-70:], len(cand)))
        return assets
    # the meme's own name (the long words of the page title) in an example's words or in its picture settles it: it stays
    names = [w for w in re.findall(r'[a-z]{5,}', re.sub(r"'s\b", '', m.get('title', '').lower())) if w not in COMMON]
    for x in j.get('off') or []:
        k = x.get('id') if isinstance(x, dict) else x
        # (what is SEEN in it, not the words it was filed under: a search for the meme's name returns its name in every title)
        if k in cand and any(n in cand[k].get('seen', '').lower() for n in names):
            log('  %s: the reader called it off topic, but the meme\'s name is in the picture: it stays' % k)
            continue
        if k in cand and k not in (j.get('on_topic') or []):
            assets[k]['usable'] = False
            if assets[k]['kind'] == 'clip':
                assets[k]['windows'], assets[k]['eyes']['relevant'] = [], False
            log('  %s: NOT THIS MEME (%s): not used' % (k, str(x.get('why', '') if isinstance(x, dict) else '')[:60]))
    log('meme pictures: %d of %d will be shown (sorted by %s)' % (sum(1 for a in assets.values() if a.get('usable')), len(assets), model))
    return assets


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    mat = json.load(open(os.path.join(sys.argv[1], 'material.json'), encoding='utf-8'))
    sort_topic(look(collect(mat, sys.argv[1]), mat, sys.argv[1]), mat)
