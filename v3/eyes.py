"""THE EYES: every clip is looked at before it is cut. A sheet of small pictures of the clip (one every few seconds,
each with a letter and its second) goes to a model that reads pictures; it says what each one shows, how good it is
as footage for this story, where the subject sits, and whether the clip shows what was asked for.
From that: the seconds worth cutting to (best first), the side-to-side position of the 9:16 window, and a refusal of
clips that are a title card, a talking head nobody asked for, or the wrong thing. Without a picture model the clip is
cut evenly (and says so in the log).  usage: eyes.py <work folder>"""
import json
import os
import subprocess
import sys

import ai

LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWX'
SYSTEM = ('You check video footage for a short news video. You get ONE contact sheet: small pictures taken from one clip, in order, each labelled with a letter and its second. '
          'Answer with strict JSON only.')


def sheet(clip, dur, out, n):
    times = [round(dur * (0.04 + 0.92 * i / max(1, n - 1)), 2) for i in range(n)]
    tiles = []
    for i, t in enumerate(times):
        f = '%s.t%02d.jpg' % (out, i)
        subprocess.run(['ffmpeg', '-y', '-v', 'error', '-ss', '%.2f' % t, '-i', clip, '-frames:v', '1', '-vf', 'scale=320:180:force_original_aspect_ratio=decrease,pad=320:180:(ow-iw)/2:(oh-ih)/2:black', '-q:v', '4', f], timeout=40)
        if os.path.isfile(f):
            tiles.append((i, t, f))
    try:
        from PIL import Image, ImageDraw
        cols = 4
        rows = (len(tiles) + cols - 1) // cols
        im = Image.new('RGB', (cols * 320, rows * 180), 'black')
        d = ImageDraw.Draw(im)
        for k, (i, t, f) in enumerate(tiles):
            x, y = (k % cols) * 320, (k // cols) * 180
            im.paste(Image.open(f), (x, y))
            d.rectangle([x, y, x + 96, y + 26], fill='black')
            d.text((x + 6, y + 6), '%s  %ds' % (LETTERS[i], int(t)), fill='white')
        im.save(out, quality=85)
        labelled = True
    except Exception:  # noqa: BLE001  (no Pillow: an unlabelled grid, read in order)
        lst = out + '.txt'
        open(lst, 'w').write(''.join("file '%s'\n" % f.replace('\\', '/') for _, _, f in tiles))
        subprocess.run(['ffmpeg', '-y', '-v', 'error', '-f', 'concat', '-safe', '0', '-i', lst, '-vf', 'tile=4x%d' % ((len(tiles) + 3) // 4), '-frames:v', '1', '-q:v', '4', out], timeout=60)
        labelled = False
    for _, _, f in tiles:
        os.remove(f)
    return [(LETTERS[i], t) for i, t, _ in tiles], labelled


def look(work, log=print):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    edir = os.path.join(work, 'eyes'); os.makedirs(edir, exist_ok=True)
    for aid, a in plan['assets'].items():
        if not a.get('file') or a['kind'] == 'photo' or a.get('eyes'):
            continue
        n = int(min(16, max(6, a['dur'] // 4)))
        out = os.path.join(edir, aid + '.jpg')
        tiles, labelled = sheet(os.path.join(work, 'assets', a['file']), a['dur'], out, n)
        tsec = dict(tiles)
        user = ('THE STORY: %s\nTHIS CLIP: %s\n%s\nThe sheet has %d pictures%s.\n'
                'For each picture: "n" its letter, "what" it shows in at most 10 words, "score" 0 to 5 as footage for THIS story (5 = clear, lively, shows the subject; 3 = usable background; '
                '1 = dull or unclear; 0 = black, a title or text screen, an ad, a logo, unrelated), "x" where the main subject sits from 0 (left edge) to 1 (right edge), '
                '"text" true if big burned-in text, captions or a channel banner cover it, "face" true if a real person\'s face is the main subject.\n'
                'Then for the whole clip: "kind" one of gameplay, trailer, event, stream, talking, other; "relevant" true if it shows the game, people, product or event of THIS story at all (false for a different game, a fan concept, an ad, an unrelated video); "exact" true if it shows exactly this: %s; "shows" one sentence.\n'
                'JSON: {"tiles":[{"n":"A","what":"","score":0,"x":0.5,"text":false,"face":false}],"kind":"","relevant":true,"exact":true,"shows":""}'
                % (plan['title'], a.get('about', '')[:300], 'It was searched to show: ' + a['must_show'] if a.get('must_show') else '', len(tiles),
                   ', each labelled with its letter and second' if labelled else ', unlabelled: letter them A, B, C... left to right, top to bottom', a.get('must_show') or 'something of this story'))
        eyes = None
        if a['kind'] != 'stock':
            try:
                eyes, model = ai.ask_json(SYSTEM, user, images=[out], temperature=0.2, timeout=60, max_tokens=6000)
                eyes['model'] = model
            except Exception as e:  # noqa: BLE001
                log('%s: no picture model answered (%s); cut evenly' % (aid, str(e)[-90:]))
        if not eyes or not isinstance(eyes.get('tiles'), list):
            eyes = {'tiles': [{'n': l, 'what': '', 'score': 3, 'x': 0.5, 'text': False, 'face': False} for l, _ in tiles], 'kind': 'other', 'relevant': True, 'shows': '', 'model': 'none'}
        good = []
        for t in eyes['tiles']:
            sec = tsec.get(str(t.get('n', '')).strip().upper()[:1])
            if sec is None:
                continue
            try:
                score, x = float(t.get('score', 0)), min(0.85, max(0.15, float(t.get('x', 0.5))))
            except (TypeError, ValueError):
                score, x = 0.0, 0.5
            if t.get('text'):
                score -= 1.5
            good.append({'t': sec, 'score': score, 'x': round(x, 2), 'face': bool(t.get('face')), 'what': str(t.get('what', ''))[:70]})
        a['eyes'] = {'kind': str(eyes.get('kind', 'other')), 'relevant': bool(eyes.get('relevant', True)), 'exact': bool(eyes.get('exact', eyes.get('relevant', True))), 'shows': str(eyes.get('shows', ''))[:160], 'model': eyes.get('model', '')}
        a['windows'] = sorted([g for g in good if g['score'] >= 2.5], key=lambda g: (-g['score'], g['t']))
        a['usable'] = bool(a['windows']) and (a['eyes']['relevant'] or a['kind'] == 'stock')
        log('%s: %s, %s, %d of %d moments usable: %s' % (aid, a['eyes']['kind'], ('shows exactly it' if a['eyes']['exact'] else 'on topic') if a['eyes']['relevant'] else 'OFF TOPIC', len(a['windows']), len(good), a['eyes']['shows'][:90]))
    json.dump(plan, open(os.path.join(work, 'plan.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    import localenv
    localenv.load()
    look(sys.argv[1])
