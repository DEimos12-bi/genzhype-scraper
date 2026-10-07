"""v2 shots: every plan line is a shot anchored on the voice (timeline.json); the picture of each shot is extracted as
1080x1920 JPEG frames at 30 fps into base/<id>/ (the owner's shots.py, driven by plan.json).
  visual.asset clipN -> frames of the clip from visual.in (mode V as is, B blurred and dark, S one still frame)
  visual.asset photoN_M -> one still, animated by the browser (camera drift)
  visual.asset card -> no base picture (paper scene); site -> no base picture (closing card)
usage: shots.py <workdir>   -> comp.json + base/"""
import json
import os
import shutil
import subprocess
import sys

FPS = 30


def say(*a):
    print(*a, flush=True)


def probe(path):
    r = subprocess.run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration', '-of', 'json', path], capture_output=True, text=True)
    try:
        j = json.loads(r.stdout)
        st = j['streams'][0]
        return int(st.get('width', 0)), int(st.get('height', 0)), float(j['format'].get('duration', 0))
    except Exception:  # noqa: BLE001
        return 0, 0, 0.0


def fit_filter(w, h):
    """A clip of any shape fills 1080x1920: vertical clips scale to fit, landscape clips are enlarged and center-cropped."""
    if w and h and w > h * 0.65:   # landscape or square: fill the height, crop the sides
        return 'scale=-2:1920:flags=lanczos,crop=1080:1920'
    return 'scale=1080:1920:force_original_aspect_ratio=increase:flags=lanczos,crop=1080:1920'


def main():
    work = sys.argv[1]
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    tl = json.load(open(os.path.join(work, 'timeline.json'), encoding='utf-8'))
    lines = {l['id']: l for l in tl['lines']}
    end = round(tl['total'] + 0.45, 3)
    shots = []
    ids = [l['id'] for l in plan['lines']]
    for k, pl in enumerate(plan['lines']):
        ln = lines[pl['id']]
        t0 = 0.0 if k == 0 else round(max(0.0, ln['s'] - 0.08), 3)
        t1 = end if k == len(ids) - 1 else round(max(0.0, lines[ids[k + 1]]['s'] - 0.08), 3)
        v = pl.get('visual') or {}
        asset = v.get('asset', 'card')
        a = plan['assets'].get(asset, {})
        kind = a.get('kind') or ('card' if asset == 'card' else 'site' if asset == 'site' else 'clip' if asset.startswith('clip') else 'photo')
        mode = v.get('mode', 'V') if kind == 'clip' else ('P' if kind == 'photo' else 'N')
        dur = t1 - t0
        n = 1 if mode in ('S', 'P') else (0 if mode == 'N' else int(round(dur * FPS)) + 3)
        shots.append({'id': pl['id'], 't0': t0, 't1': t1, 'n': n, 'mode': mode, 'dir': pl['id'], 'asset': asset, 'kind': kind, 'credit': a.get('credit', ''), 'in': float(v.get('in', 0) or 0)})
    comp = {'fps': FPS, 'end': end, 'frames': int(round(end * FPS)), 'shots': shots, 'lines': tl['lines'], 'plan': plan}
    json.dump(comp, open(os.path.join(work, 'comp.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    open(os.path.join(work, 'comp.js'), 'w', encoding='utf-8').write('window.COMP = ' + json.dumps(comp, ensure_ascii=False) + ';')
    base = os.path.join(work, 'base')
    for s in shots:
        if s['mode'] == 'N':
            continue
        folder = os.path.join(base, s['dir'])
        shutil.rmtree(folder, ignore_errors=True); os.makedirs(folder)
        a = plan['assets'][s['asset']]
        src = os.path.join(work, 'assets', a['file'])
        if s['kind'] == 'photo':
            subprocess.run(['ffmpeg', '-y', '-v', 'error', '-i', src, '-vf', 'scale=1080:1920:force_original_aspect_ratio=increase:flags=lanczos,crop=1080:1920', '-frames:v', '1', '-q:v', '3', os.path.join(folder, '0001.jpg')], check=True)
            say('%-8s %6.2f-%6.2f photo' % (s['id'], s['t0'], s['t1']))
            continue
        w, h, clipdur = probe(src)
        dur = s['t1'] - s['t0']
        ss = s['in']
        if clipdur and ss + dur > clipdur:            # the clip is shorter than the shot: start earlier, or slow it down
            ss = max(0.0, clipdur - dur - 0.2)
        speed = 1.0
        if clipdur and clipdur < dur:
            speed = max(0.5, clipdur / dur)
        fit = fit_filter(w, h)
        pace = 'setpts=PTS/%.5f,fps=%d' % (speed, FPS)
        if s['mode'] == 'S':
            vf = '%s,unsharp=5:5:0.5' % fit
        elif s['mode'] == 'B':
            vf = '%s,%s,gblur=sigma=16,eq=brightness=-0.16:saturation=0.9' % (pace, fit)
        else:
            vf = '%s,%s,unsharp=5:5:0.45' % (pace, fit)
        cmd = ['ffmpeg', '-y', '-v', 'error', '-ss', '%.3f' % ss, '-t', '%.3f' % (dur * speed + 0.6), '-i', src, '-vf', vf, '-frames:v', str(s['n']), '-q:v', '3', os.path.join(folder, '%04d.jpg')]
        subprocess.run(cmd, check=True)
        got = len(os.listdir(folder))
        if got < s['n']:                                 # a short clip: hold the last frame
            last = os.path.join(folder, '%04d.jpg' % got)
            for i in range(got + 1, s['n'] + 1):
                shutil.copy(last, os.path.join(folder, '%04d.jpg' % i))
        say('%-8s %6.2f-%6.2f  %s@%.2f x%.2f %s frames %d (%dx%d)' % (s['id'], s['t0'], s['t1'], s['asset'], ss, speed, s['mode'], got, w, h))
    say('end', end, 'frames', comp['frames'])


if __name__ == '__main__':
    main()
