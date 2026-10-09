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
import subprocess
import sys

import ai
import footage

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
        user = ('THE MEME: %s\nWHAT IT IS: %s\n\nA page about this meme links this ONE picture as an example of it%s. About this picture:\n'
                '"what": what it shows, in at most 16 words (who or what, doing what; name a character only if the text in the picture names it or it is unmistakable);\n'
                '"text": the words written in the picture, if any (at most 10 words);\n'
                '"on_topic": true if it shows this meme, its characters or its subject, false if it clearly shows something else;\n'
                '"person": "real" if a real human being (photographed or filmed) is the main subject, "drawn" if its figures are drawn, animated, 3D-rendered or game characters, "none" if nobody is in it;\n'
                '"score": 0 to 5, how clear and striking it is as a picture (0 = black, blank or unreadable).\n'
                'JSON: {"what":"","text":"","on_topic":true,"person":"none","score":3}'
                % (m.get('title', ''), (m.get('summary') or m.get('page_text', ''))[:600], ' (it was posted with the words "%s")' % a['title'][:120] if a.get('title') and a['from'] != 'gif' else ''))
        try:
            t, model = ai.ask_json('You look at ONE picture for a short video about an internet meme. Answer with strict JSON only.', user, images=[tile], temperature=0.1, timeout=60, max_tokens=500)
        except Exception as e:  # noqa: BLE001
            log('  %s: no picture model answered (%s)' % (k, str(e)[-80:]))
        try:
            score = float((t or {}).get('score', 3))
        except (TypeError, ValueError):
            score = 3.0
        a['looked'], a['score'] = model, score
        a['seen'] = (str(t.get('what', '')).strip()[:110] + (' (text in it: "%s")' % str(t['text']).strip()[:70] if str(t.get('text') or '').strip() else '')) if t else ''
        a['usable'], why = bool(t) and bool(t.get('on_topic', True)) and score >= 1, 'NOT THIS MEME' if t else 'NOBODY LOOKED AT IT'
        # A real person as the subject: a GIF of a stranger turned into a joke is never shown; somebody's own post only when
        # the owner allows it ("memes": {"real_people": true} in config.json). Nobody watches these videos before they exist,
        # which is also why a picture that no model looked at is not used.
        if a['usable'] and str(t.get('person', '')).lower().startswith('real') and (a['from'] == 'gif' or not ai.CONFIG.get('memes', {}).get('real_people')):
            a['usable'], why = False, 'A REAL PERSON IS ITS SUBJECT'
        if a['kind'] == 'clip':                                # a GIF needs no second look by the eyes step
            a['eyes'] = {'kind': 'meme', 'relevant': a['usable'], 'exact': a['usable'], 'shows': a['seen'][:160], 'model': model}
            a['windows'] = [{'t': 0.0, 'score': 4.0, 'x': 0.5, 'face': False, 'what': a['seen'][:70]}] if a['usable'] else []
        log('  %s (%s by %s): %s%s' % (k, a['from'], a['by'], a['seen'] or 'not looked at', '' if a['usable'] else '  -> %s: not used' % why))
        if tick:
            tick()
    return assets


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    mat = json.load(open(os.path.join(sys.argv[1], 'material.json'), encoding='utf-8'))
    look(collect(mat, sys.argv[1]), mat, sys.argv[1])
