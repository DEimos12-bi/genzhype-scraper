"""SCENES: where a clip cuts. A trailer is thirty different pictures, a stream clip is one take. The cuts are found by
PySceneDetect (github.com/Breakthrough/PySceneDetect, BSD-3-Clause; it lives in the tools' own Python, scenes_run.py)
on a small copy of the clip, so a minute of footage takes a few seconds; where that Python is missing, ffmpeg's own
scene score does the job. From the cuts:
  of(clip, seconds)      -> the scenes [[start, end], ...], the whole clip covered
  sample(scenes, seconds) -> the moments the eyes are shown: one in EVERY scene (more in a long one), instead of one
                             every few seconds, which showed the same scene four times and never saw the others
The cut then takes each shot from a scene that was not shown yet (shots.Supply).  usage: scenes.py <clip>"""
import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))


def tool_python():
    """The Python that has PySceneDetect: the voice's own (a machine setting), or the folder it is made in. '' when none."""
    import ai
    fb = ai.CONFIG.get('voice_fallback') or {}
    for py in (fb.get('python'), ai.CONFIG.get('voice_python'), os.path.join(HERE, '.venv-voice', 'Scripts', 'python.exe'), os.path.join(HERE, '.venv-voice', 'bin', 'python')):
        if py and os.path.isfile(py):
            return py
    return ''


def cuts_tool(small):
    py = tool_python()
    if not py:
        return None
    try:
        r = subprocess.run([py, os.path.join(HERE, 'scenes_run.py'), small], capture_output=True, text=True, timeout=120)
        return [float(x) for x in json.loads(r.stdout.strip().splitlines()[-1])]
    except Exception:  # noqa: BLE001  (not installed there, or the file could not be read)
        return None


def cuts_ffmpeg(small):
    r = subprocess.run(['ffmpeg', '-v', 'info', '-i', small, '-vf', "select='gt(scene,0.3)',showinfo", '-f', 'null', '-'], capture_output=True, text=True, timeout=120)
    return [float(x) for x in re.findall(r'pts_time:([\d.]+)', r.stderr or '')]


def of(path, dur, log=None):
    """The scenes of a clip, [[start, end], ...] in seconds. One scene when nothing cuts or the clip could not be read."""
    small, cuts, by = path + '.small.mp4', None, ''
    try:
        subprocess.run(['ffmpeg', '-y', '-v', 'error', '-i', path, '-an', '-vf', 'fps=12,scale=256:-2', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '30', small], timeout=120)
        if os.path.isfile(small):
            cuts, by = cuts_tool(small), 'PySceneDetect'
            if cuts is None:
                cuts, by = cuts_ffmpeg(small), "ffmpeg's scene score"
    except Exception:  # noqa: BLE001
        cuts = None
    finally:
        if os.path.exists(small):
            os.remove(small)
    edges = [0.0]
    for c in sorted(cuts or []):
        if c - edges[-1] >= 0.5 and dur - c >= 0.5:
            edges.append(round(c, 2))
    edges.append(round(dur, 2))
    out = [[a, b] for a, b in zip(edges, edges[1:])]
    if log and by:
        log('  %d scene(s) (%s)' % (len(out), by))
    return out


def sample(sc, dur, lo=6, hi=24):
    """The moments to look at: one in every scene, one every few seconds inside a long scene, `hi` at most (the longest
    scenes when there are more), `lo` at least in a clip long enough. -> [(second, number of its scene)], in order."""
    sc = sc or [[0.0, dur]]
    idx = [i for i, (a, b) in enumerate(sc) if b - a >= 0.8] or list(range(len(sc)))
    if len(idx) > hi:
        idx = sorted(sorted(idx, key=lambda i: sc[i][0] - sc[i][1])[:hi])
    count = lambda step: {i: max(1, int(round((sc[i][1] - sc[i][0]) / step))) for i in idx}
    step = max(4.0, dur / 16.0)
    while sum(count(step).values()) > hi:
        step *= 1.25
    while sum(count(step).values()) < min(lo, int(dur // 1.5)) and step > 0.8 and sum(count(step * 0.8).values()) <= hi:
        step *= 0.8
    out = []
    for i, n in count(step).items():
        a, b = sc[i]
        pad = min(0.4, (b - a) * 0.2)
        out += [(round(a + pad + (b - a - 2 * pad) * (k + 0.5) / n, 2), i) for k in range(n)]
    return sorted(out)[:hi]


if __name__ == '__main__':
    import footage
    w, h, d = footage.probe(sys.argv[1])
    found = of(sys.argv[1], d, print)
    print(found)
    print(sample(found, d))
