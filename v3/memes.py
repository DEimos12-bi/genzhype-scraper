"""A MEME'S OWN PICTURES. A meme page links the meme itself: GIPHY GIFs, TikTok and YouTube posts that use it. They are
fetched and LOOKED AT before the script is written, so that the director writes to pictures it knows, and the video
shows the meme from the first second to the last.
  collect(m, work)   every example into <work>/assets: a GIF as a short looping clip, a post as its preview picture
                     (the public embed data of TikTok and YouTube: no login, nothing is worked around);
  look(assets, m, work)   one labelled sheet of all of them goes to the picture model: what each one shows, and whether
                     it shows THIS meme. An example that shows something else is not used (a page's list of examples
                     is not always clean).
usage: memes.py <work folder>     (prints what was fetched and seen; the director does this by itself)"""
import json
import math
import os
import subprocess
import sys

import ai
import footage

LETTERS = 'ABCDEFGHIJKL'
PREVIEWS = ('https://i.ytimg.com/vi/%s/oar2.jpg', 'https://i.ytimg.com/vi/%s/maxresdefault.jpg', 'https://i.ytimg.com/vi/%s/hqdefault.jpg')      # a Short's upright picture first


def collect(m, work, log=print):
    adir = os.path.join(work, 'assets'); os.makedirs(adir, exist_ok=True)
    out = {}
    for i, ex in enumerate((m.get('examples') or [])[:len(LETTERS)]):
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


def sheet(assets, work):
    """One picture of every example side by side, each with its letter."""
    from PIL import Image, ImageDraw
    edir = os.path.join(work, 'eyes'); os.makedirs(edir, exist_ok=True)
    keys, cell, cols = list(assets)[:len(LETTERS)], 384, 4
    im = Image.new('RGB', (cols * cell, ((len(keys) + cols - 1) // cols) * cell), 'black')
    d = ImageDraw.Draw(im)
    for n, k in enumerate(keys):
        a, f = assets[k], os.path.join(edir, k + '.tile.jpg')
        src = os.path.join(work, 'assets', a['file'])
        subprocess.run(['ffmpeg', '-y', '-v', 'error'] + (['-ss', '%.2f' % (a.get('loop', 1.0) * 0.45)] if a['kind'] == 'clip' else []) + ['-i', src, '-frames:v', '1',
                        '-vf', 'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black' % (cell, cell, cell, cell), '-q:v', '3', f], timeout=40)
        x, y = (n % cols) * cell, (n // cols) * cell
        if os.path.isfile(f):
            im.paste(Image.open(f), (x, y))
            os.remove(f)
        d.rectangle([x, y, x + 46, y + 34], fill='black')
        d.text((x + 14, y + 9), LETTERS[n], fill='white')
    out = os.path.join(edir, 'memes.jpg')
    im.save(out, quality=86)
    return out, keys


def look(assets, m, work, log=print):
    """Sets on every example: "seen" (what it shows), "usable" (it shows this meme) and, for a GIF, what the cut needs."""
    if not assets:
        return assets
    seen, model = {}, 'none'
    try:
        out, keys = sheet(assets, work)
        user = ('THE MEME: %s\nWHAT IT IS: %s\n\nThe sheet has %d pictures, each labelled with a letter. A page about this meme links them as examples of it. For each picture: "n" its letter, '
                '"what" it shows in at most 16 words (who or what, doing what; name a character only if the text in the picture names it or it is unmistakable), '
                '"text" the words written in the picture, if any (at most 10 words), "on_topic" true if it shows this meme, its characters or its subject, false if it clearly shows something else, '
                '"person" true if a REAL human being (photographed or filmed; not a drawing, not a cartoon, not a game or 3D character) is the main subject, '
                '"score" 0 to 5: how clear and striking it is as a picture (0 = black, blank or unreadable).\n'
                'JSON: {"tiles":[{"n":"A","what":"","text":"","on_topic":true,"person":false,"score":3}]}' % (m.get('title', ''), (m.get('summary') or m.get('page_text', ''))[:700], len(keys)))
        j, model = ai.ask_json('You look at pictures for a short video about one internet meme. You get ONE sheet of small pictures. Answer with strict JSON only.', user, images=[out],
                               temperature=0.2, timeout=ai.CONFIG['ai'].get('timeout', 100), max_tokens=3000)
        for t in j.get('tiles') or []:
            n = str(t.get('n', '')).strip().upper()[:1]
            if n and n in LETTERS[:len(keys)]:
                seen[keys[LETTERS.index(n)]] = t
    except Exception as e:  # noqa: BLE001
        log('  no picture model looked at the meme pictures (%s): all are kept, the script is written without knowing them' % str(e)[-90:])
    for k, a in assets.items():
        t = seen.get(k) or {}
        try:
            score = float(t.get('score', 3))
        except (TypeError, ValueError):
            score = 3.0
        a['seen'] = (str(t.get('what', '')).strip()[:110] + (' (text in it: "%s")' % str(t['text']).strip()[:70] if str(t.get('text') or '').strip() else '')) if t else ''
        a['usable'], a['score'], why = bool(t.get('on_topic', True)) and score >= 1, score, 'NOT THIS MEME'
        # A real person as the subject: a GIF of a stranger turned into a joke is never shown; somebody's own post only when
        # the owner allows it ("memes": {"real_people": true} in config.json). Nobody watches these videos before they exist.
        if a['usable'] and t.get('person') and (a['from'] == 'gif' or not ai.CONFIG.get('memes', {}).get('real_people')):
            a['usable'], why = False, 'A REAL PERSON IS ITS SUBJECT'
        if a['kind'] == 'clip':                                # a GIF needs no second look by the eyes step
            a['eyes'] = {'kind': 'meme', 'relevant': a['usable'], 'exact': a['usable'], 'shows': a['seen'][:160], 'model': model}
            a['windows'] = [{'t': 0.0, 'score': 4.0, 'x': 0.5, 'face': False, 'what': a['seen'][:70]}] if a['usable'] else []
        log('  %s (%s by %s): %s%s' % (k, a['from'], a['by'], a['seen'] or 'not looked at', '' if a['usable'] else '  -> %s: not used' % why))
    return assets


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    mat = json.load(open(os.path.join(sys.argv[1], 'material.json'), encoding='utf-8'))
    look(collect(mat, sys.argv[1]), mat, sys.argv[1])
