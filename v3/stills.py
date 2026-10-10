"""STILLS CUT OUT OF A CLIP. One clip gives a video several pictures: the moment the eyes rated best, a face when there
is one, a different scene. Each is a still asset of its own (close-ups, pans, two windows work on it), with the clip's
credit and the eyes' words; the picture model then lists what is in it and where (memes.look). The way a person cuts
frames out of a stream to open on a face or a banner. Called by gather.py, before a word is written."""
import os
import subprocess


def pick_moments(windows, dur, want=3):
    """Up to `want` moments of a clip: the best rated first, at least 8 s apart, no tile with text across it, and one
    with a face when the clip has one (so a close-up of the person exists)."""
    good = [w for w in windows if w.get('score', 0) >= 2.5 and not w.get('text')]
    out = []
    for w in sorted(good, key=lambda w: (-w.get('score', 0), w.get('t', 0))):
        if all(abs(w['t'] - o['t']) >= 8 for o in out):
            out.append(w)
        if len(out) >= want:
            break
    if not any(o.get('face') for o in out):
        face = next((w for w in sorted(good, key=lambda w: -w.get('score', 0)) if w.get('face')), None)
        if face and all(abs(face['t'] - o['t']) >= 4 for o in out):
            out = (out + [face])[-want:] if len(out) >= want else out + [face]
    return sorted(out, key=lambda w: w['t'])


def cut_from_clips(assets, work, log=print, want=3):
    """Adds `<clip>_s<k>` photo assets for every clip not yet cut. -> the ids added."""
    adir, added = os.path.join(work, 'assets'), []
    for cid, a in list(assets.items()):
        if a.get('kind') != 'clip' or not a.get('file') or a.get('stills_cut') or not a.get('usable', True):
            continue
        dur = float(a.get('dur') or 0)
        if a.get('whole'):                                     # a meme's own clip (a GIF, a TikTok): one still at its clearest point
            moments = [{'t': round(dur * 0.45, 2), 'score': 4, 'x': 0.5, 'face': False, 'what': a.get('seen', '')}] if dur >= 1 else []
        else:
            moments = pick_moments(a.get('windows') or [], dur, want)
        for k, w in enumerate(moments):
            sid, name = '%s_s%d' % (cid, k + 1), '%s_s%d.jpg' % (cid, k + 1)
            r = subprocess.run(['ffmpeg', '-y', '-v', 'error', '-ss', '%.2f' % w['t'], '-i', os.path.join(adir, a['file']), '-frames:v', '1', '-q:v', '2', os.path.join(adir, name)], capture_output=True, timeout=40)
            if r.returncode != 0 or not os.path.isfile(os.path.join(adir, name)):
                continue
            assets[sid] = {'kind': 'photo', 'file': name, 'from_clip': cid, 'at': w['t'], 'credit': a.get('credit', ''), 'by': a.get('by', ''), 'page': a.get('page', ''), 'post': a.get('post'),
                           'about': ('a moment of the clip at %d s: %s' % (w['t'], w.get('what') or a.get('about', ''))).strip(), 'title': a.get('title', ''),
                           'eyes': {'kind': (a.get('eyes') or {}).get('kind', 'other'), 'relevant': True, 'exact': True, 'shows': w.get('what') or (a.get('eyes') or {}).get('shows', '')},
                           'windows': [{'t': 0.0, 'score': w.get('score', 3), 'x': w.get('x', 0.5), 'face': bool(w.get('face')), 'what': w.get('what', '')}], 'cut_still': True}
            if a.get('whole'):
                assets[sid]['whole'] = True
            added.append(sid)
        a['stills_cut'] = True
    if added:
        log('stills: %d cut out of the clips (%s)' % (len(added), ', '.join(added)))
    return added
