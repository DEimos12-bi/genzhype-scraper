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

HUMAN = re.compile(r'\b(man|woman|boy|girl|guy|person|people|teen\w*|kids?|child|children|lady|men|women|player|student|someone)\b', re.I)

PREVIEWS = ('https://i.ytimg.com/vi/%s/oar2.jpg', 'https://i.ytimg.com/vi/%s/maxresdefault.jpg', 'https://i.ytimg.com/vi/%s/hqdefault.jpg')      # a Short's upright picture first


def collect(m, work, log=print):
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    out = {}
    for i, ex in enumerate((m.get('examples') or [])[:10]):
        aid = 'meme%d' % i
        a = {'whole': True, 'from': ex['kind'], 'by': ex.get('by', ''), 'page': ex.get('page', ''), 'title': ex.get('title', ''), 'date': ex.get('date', ''), 'about': ex.get('title', '')[:240]}
        who = ''.join(c for c in ex.get('by', '').upper() if c.isalnum() or c in '@._- ')[:24].strip()
        try:
            if ex['kind'] == 'gif':
                raw, dest = os.path.join(adir, aid + '.gif.mp4'), os.path.join(adir, aid + '.mp4')
                footage.save(ex['video'], raw, timeout=40)
                w, h, dur = footage.probe(raw)
                if not w or dur <= 0:
                    raise ValueError('unreadable GIF')
                loops = max(0, int(math.ceil(13.0 / dur)) - 1)   # a GIF lasts a second or two: it is looped, so a shot can start at different points of it
                footage.run(['ffmpeg', '-y', '-v', 'error', '-stream_loop', str(loops), '-i', raw, '-t', '14', '-an', '-vf', 'fps=30,scale=trunc(iw/2)*2:trunc(ih/2)*2',
                             '-c:v', 'libx264', '-crf', '17', '-pix_fmt', 'yuv420p', dest], 80)
                os.remove(raw)
                a.update(kind='clip', file=aid + '.mp4', credit='GIF · %s / GIPHY' % who, loop=round(dur, 2))
                a['w'], a['h'], a['dur'] = footage.probe(dest)
            else:
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
                '"what": what it shows, in at most 16 words (who or what, doing what);\n'
                '"text": the words written in the picture, exactly as written (at most 12 words; "" if there are none);\n'
                '"person": "real" if a real human being (photographed or filmed) is the main subject, "drawn" if its figures are drawn, animated, 3D-rendered or game characters, "none" if nobody is in it;\n'
                '"score": 0 to 5, how clear and striking it is as a picture (0 = black, blank or unreadable).\n'
                'JSON: {"what":"","text":"","person":"none","score":3}')
        try:
            t, model = ai.ask_json('You look at ONE picture. Answer with strict JSON only.', user, images=[tile], tries=3, temperature=0.1, timeout=60, max_tokens=500)
        except ai.OutOfTime:
            raise                                              # the caller's time limit: this picture is looked at in the next run
        except Exception as e:  # noqa: BLE001
            log('  %s: no picture model answered (%s)' % (k, str(e)[-80:]))
        try:
            score = float((t or {}).get('score', 3))
        except (TypeError, ValueError):
            score = 3.0
        what, person = str((t or {}).get('what', '')).strip()[:110], str((t or {}).get('person', '')).lower()
        a['looked'], a['score'] = model, score
        a['seen'] = (what + (' (text in it: "%s")' % str(t['text']).strip()[:80] if str(t.get('text') or '').strip() else '')) if t else ''
        a['usable'], why = bool(t) and score >= 1, 'UNREADABLE' if t else 'NOBODY LOOKED AT IT'
        # A real person as the subject: a GIF of a stranger turned into a joke is never shown; somebody's own post only when
        # the owner allows it ("memes": {"real_people": true} in config.json). Nobody watches these videos before they exist,
        # which is also why a picture that no model looked at is not used. A description that names a human being counts as
        # a real person unless the model says the figures are drawn.
        a['person'] = 'real' if person.startswith('real') or (not person.startswith('drawn') and HUMAN.search(what)) else 'drawn' if person.startswith('drawn') else 'none'
        if a['usable'] and a['person'] == 'real' and (a['from'] == 'gif' or not ai.CONFIG.get('memes', {}).get('real_people')):
            a['usable'], why = False, 'A REAL PERSON IS ITS SUBJECT'
        if a['kind'] == 'clip':                                # a GIF needs no second look by the eyes step
            a['eyes'] = {'kind': 'meme', 'relevant': a['usable'], 'exact': a['usable'], 'shows': a['seen'][:160], 'model': model}
            a['windows'] = [{'t': 0.0, 'score': 4.0, 'x': 0.5, 'face': False, 'what': a['seen'][:70]}] if a['usable'] else []
        log('  %s (%s by %s): %s%s' % (k, a['from'], a['by'], a['seen'] or 'not looked at', '' if a['usable'] else '  -> %s: not used' % why))
        if tick:
            tick()
    return assets


def sort_topic(assets, m, log=print):
    """Which examples show THIS meme: decided by a text reader from the blind descriptions and the words posted with each
    one (a page's list of examples is not always clean). Without a reader they all stay."""
    cand = {k: a for k, a in assets.items() if a.get('usable')}
    if len(cand) < 2:
        return assets
    kinds = {'gif': 'a GIF', 'tiktok': 'a TikTok post', 'youtube': 'a YouTube video'}
    rows = '\n'.join('- %s (%s by %s%s): the picture shows: %s' % (k, kinds.get(a['from'], 'a picture'), a.get('by') or '?', ', posted with the words "%s"' % a['title'][:140] if a.get('title') else '', a.get('seen') or '?') for k, a in cand.items())
    user = ('THE MEME: %s\nWHAT IT IS: %s\n\nA page about this meme links these as examples of it. A picture model, which was not told what the meme is, wrote what each picture shows.\n%s\n\n'
            'Which of them are about THIS meme: the picture shows it, its characters or its subject, or the words posted with it name it? Put in "off" only what is clearly about something else.\n'
            'JSON: {"on_topic":["meme0"],"off":[{"id":"meme9","why":"max 8 words"}]}' % (m.get('title', ''), (m.get('summary') or m.get('page_text', ''))[:700], rows))
    try:
        j, model = ai.ask_json('You sort the pictures of a short video about one internet meme. Strict JSON only.', user, kind='reader', temperature=0.1, timeout=60, max_tokens=800)
    except ai.AIError as e:
        log('  no reader sorted the examples (%s): all %d stay' % (str(e)[-70:], len(cand)))
        return assets
    for x in j.get('off') or []:
        k = x.get('id') if isinstance(x, dict) else x
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
