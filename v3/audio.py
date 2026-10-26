"""THE SOUND: the voice, the music and effects, placed by the cue list that shots.py wrote from the graphics.
The music is a real recorded track (music.json: licensed with a credit), chosen by the mood of this video's sections and
never the one of the last videos; code-made music is only the fallback when no track can be read. The effects are
made here in code. Writes mix.wav (48 kHz stereo) and music_used.json (the credit for the caption); prints the levels.
usage: audio.py <work folder>"""
import json
import os
import subprocess
import sys
import wave

import numpy as np

HERE = os.path.abspath(sys.argv[1])          # the work folder of this video
SR = 48000
C = json.load(open(os.path.join(HERE, 'comp.json'), encoding='utf-8'))
CUES = C['cues']
END = C['end']
N = int(END * SR) + SR // 4
LN = {l['id']: l for l in C['lines']}
SH = {s['id']: s for s in C['shots']}
rng = np.random.default_rng(7)


def W(i, word, nth=1):
    k = 0
    for w in LN[i]['words']:
        if w['w'].lower().strip('.,?!:') == word.lower():
            k += 1
            if k == nth:
                return w['s']
    raise KeyError((i, word))


def T0(i):
    return SH[i]['t0']


def filt(x, lo=None, hi=None, shelf=None):
    """FFT filter: band limits in Hz (soft edges); shelf = (freq, gain_db) high shelf."""
    X = np.fft.rfft(x)
    f = np.fft.rfftfreq(len(x), 1 / SR)
    g = np.ones_like(f)
    if lo:
        g *= 1 / np.sqrt(1 + (lo / np.maximum(f, 1e-6)) ** 6)
    if hi:
        g *= 1 / np.sqrt(1 + (f / hi) ** 6)
    if shelf:
        g *= 1 + (10 ** (shelf[1] / 20) - 1) / (1 + (shelf[0] / np.maximum(f, 1e-6)) ** 4)
    return np.fft.irfft(X * g, len(x))


def env_follow(x, attack=0.02, release=0.25):
    a = np.abs(x)
    hop = 480
    frames = a[:len(a) // hop * hop].reshape(-1, hop).max(axis=1)
    out, e = np.empty_like(frames), 0.0
    ka, kr = np.exp(-hop / SR / attack), np.exp(-hop / SR / release)
    for i, v in enumerate(frames):
        k = ka if v > e else kr
        e = k * e + (1 - k) * v
        out[i] = e
    return np.interp(np.arange(len(x)), np.arange(len(frames)) * hop + hop / 2, out)


def add(buf, sig, t, gain=1.0, pan=0.0):
    i = int(t * SR)
    if i < 0:
        sig, i = sig[-i:], 0
    n = min(len(sig), buf.shape[1] - i)
    if n <= 0:
        return
    l, r = np.cos((pan + 1) * np.pi / 4), np.sin((pan + 1) * np.pi / 4)
    buf[0, i:i + n] += sig[:n] * gain * l * 1.414
    buf[1, i:i + n] += sig[:n] * gain * r * 1.414


def tt(dur):
    return np.arange(int(dur * SR)) / SR


# ---------- instruments ----------
def kick():
    t = tt(0.42)
    f = 46 + 120 * np.exp(-t * 30)
    x = np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 8.5)
    x[:240] += rng.uniform(-1, 1, 240) * np.linspace(.5, 0, 240)
    return np.tanh(1.8 * x)


def sub(f, dur=0.75):
    t = tt(dur)
    ff = f * (1 + .6 * np.exp(-t * 45))
    e = np.minimum(1, t / .004) * np.exp(-t * 2.6) * np.minimum(1, (dur - t) / .03)
    return np.tanh(3.2 * np.sin(2 * np.pi * np.cumsum(ff) / SR) * e) * .8


def clap():
    t = tt(0.34)
    e = sum(np.where(t >= d, np.exp(-(t - d) * 85), 0) for d in (0, .011, .023)) * .5 + np.where(t >= .03, np.exp(-(t - .03) * 20), 0)
    return filt(rng.uniform(-1, 1, len(t)), 900, 6500) * e


def hat(dur=0.05, k=95):
    t = tt(dur)
    return filt(rng.uniform(-1, 1, len(t)), 7000, None) * np.exp(-t * k)


def pluck(f, dur=1.1, bright=.55, seed=1):
    n = max(8, int(4 * SR / f))          # 4x oversampled so the note lands on pitch; resampled below
    rate = f * (n + .5)
    r = np.random.default_rng(seed)
    b = r.uniform(-1, 1, n)
    for _ in range(int((1 - bright) * 8) * 6 + 6):
        b = .5 * (b + np.roll(b, 1))
    per = int(dur * f) + 2
    out = np.empty(per * n)
    for k in range(per):
        out[k * n:(k + 1) * n] = b
        b = .9965 * .5 * (b + np.roll(b, 1))
        if k % 3 == 0:
            b = .5 * (b + np.roll(b, 2))
    m = int(dur * SR)
    out = np.interp(np.arange(m) * rate / SR, np.arange(len(out)), out)
    out[-480:] *= np.linspace(1, 0, 480)
    return out / (np.max(np.abs(out)) + 1e-9)


def whoosh(dur=.38, lo=.5, hi=2.2, peak=.72):
    n = int(dur * SR)
    src = filt(rng.uniform(-1, 1, n * 3), 350, 2600)
    rate = lo * (hi / lo) ** np.linspace(0, 1, n)
    x = np.interp(np.cumsum(rate), np.arange(len(src)), src)
    p = np.linspace(0, 1, n)
    e = np.where(p < peak, (p / peak) ** 2.2, ((1 - p) / (1 - peak)) ** 1.4)
    return x * e


def riser(dur=1.2):
    n = int(dur * SR)
    src = filt(rng.uniform(-1, 1, n * 4), 500, 5000)
    rate = .35 * (3.2 / .35) ** np.linspace(0, 1, n)
    x = np.interp(np.cumsum(rate), np.arange(len(src)), src)
    p = np.linspace(0, 1, n)
    tone = np.sin(2 * np.pi * np.cumsum(180 * (7 ** p)) / SR) * .25
    return (x + tone) * p ** 2.6


def impact(size=1.0):
    t = tt(.9)
    f = 34 + 70 * np.exp(-t * 16)
    low = np.tanh(2.6 * np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * (5.5 / size)))
    crack = filt(rng.uniform(-1, 1, len(t)), 120, 3200) * np.exp(-t * 26) * .9
    return low * .62 + crack


def beep(f, dur=.07, k=40):
    t = tt(dur)
    return (np.sin(2 * np.pi * f * t) + .3 * np.sin(4 * np.pi * f * t)) * np.exp(-t * k) * np.minimum(1, t / .002)


def ding():
    t = tt(.55)
    return (np.sin(2 * np.pi * 1318.5 * t) + .6 * np.sin(2 * np.pi * 1975.5 * t) + .3 * np.sin(2 * np.pi * 2637 * t)) * np.exp(-t * 9) * np.minimum(1, t / .003) * .5


def buzz():
    t = tt(.22)
    return np.tanh(4 * np.sin(2 * np.pi * 138 * t)) * np.minimum(1, (0.22 - t) / .03) * .5 + filt(np.sign(np.sin(2 * np.pi * 138 * t)), 300, 2500) * .25 * np.minimum(1, (0.22 - t) / .03)


def pop():
    t = tt(.09)
    f = 520 + 900 * np.exp(-t * 60)
    return np.sin(2 * np.pi * np.cumsum(f) / SR) * np.exp(-t * 55)


def swish(dur):
    n = int(dur * SR)
    p = np.linspace(0, 1, n)
    return filt(rng.uniform(-1, 1, n), 2200, 7000) * np.sin(np.pi * p) ** .7 * .5


# ---------- music ----------
BPM = CUES.get('bpm', 128)
STEP = 60 / BPM / 4
BAR = STEP * 16
CHORDS = {'Dm': (73.42, [293.66, 349.23, 440.0, 587.33]), 'Bb': (58.27, [233.08, 293.66, 349.23, 466.16]), 'A': (55.0, [220.0, 277.18, 329.63, 440.0])}
TR = 2 ** (CUES.get('transpose', 0) / 12)
CHORDS = {k: (r * TR, [f * TR for f in fs]) for k, (r, fs) in CHORDS.items()}
PROG = ['Dm', 'Dm', 'Bb', 'A']


def chord_at(bar):
    return CHORDS[PROG[(bar // 2) % 4]]


SECTIONS = [tuple(x) for x in CUES['sections']] + [(END + 1, 'end')]


def section(t):
    kind = 'intro'
    for a, k in SECTIONS:
        if t >= a:
            kind = k
    return kind


def music():
    drums, bass, mel = np.zeros((2, N)), np.zeros((2, N)), np.zeros((2, N))
    K, CL, HC, HO = kick(), clap(), hat(), hat(.22, 22)
    cache = {}
    bars = int(END / BAR) + 1
    for bar in range(bars):
        root, tones = chord_at(bar)
        for st in range(16):
            t = bar * BAR + st * STEP
            if t >= END:
                break
            k = section(t)
            # drums
            if k in ('groove', 'full', 'bright'):
                if st in ((0, 6, 10) if bar % 2 == 0 else (0, 3, 10, 14)):
                    add(drums, K, t, .68)
                if st == 8:
                    add(drums, CL, t, .62)
                if st % 2 == 0:
                    add(drums, HC, t, .20 if st % 4 else .30, .25)
                if k == 'full' and bar % 2 == 1 and st in (13, 15):
                    add(drums, HC, t, .16, -.3); add(drums, HC, t + STEP / 2, .13, -.3)
                if st == 14 and bar % 4 == 3:
                    add(drums, HO, t, .2, .3)
            elif k == 'tense':
                add(drums, HC, t, .22 if st % 4 == 0 else .12, .2 if st % 2 else -.2)
            elif k in ('intro', 'desk'):
                if st % 4 == 0:
                    add(drums, HC, t, .2, 0)
                if k == 'desk' and st == 8:
                    add(drums, beep(1760, .03, 120), t, .06)
            # bass
            if k in ('groove', 'full') and st in ((0, 10) if bar % 2 == 0 else (0, 3, 10)):
                add(bass, sub(root, .8 if st == 0 else .5), t, .33)
            elif k in ('intro', 'desk', 'bright') and st == 0:
                add(bass, sub(root, 1.3), t, .26)
            # plucked riff
            pat = {0: 0, 2: 2, 4: 1, 6: 2, 8: 3, 10: 2, 12: 1, 14: 2}
            if st in pat:
                on = k in ('groove', 'full', 'bright') or (k in ('intro', 'desk', 'tense') and st in (0, 8)) or (k == 'tense' and st % 4 == 0)
                if on:
                    f = tones[pat[st]] * (2 if k == 'bright' else 1)
                    key = (round(f, 1), k == 'bright')
                    if key not in cache:
                        cache[key] = pluck(f, 1.2, .62 if k == 'bright' else .5, seed=int(f))
                    g = (.34 if st in (0, 8) else .22) * (1.15 if k == 'full' else 1)
                    add(mel, cache[key], t, g, -.25)
                    add(mel, cache[key], t + STEP * 3, g * .32, .55)          # echo
                    add(mel, cache[key], t + STEP * 6, g * .13, -.55)
    # pad: detuned saws on the chord, low-passed
    pad = np.zeros(N)
    t = np.arange(N) / SR
    for bar in range(0, bars, 2):
        root, tones = chord_at(bar)
        a, b = int(bar * BAR * SR), min(N, int((bar + 2) * BAR * SR))
        if a >= N:
            break
        seg = np.zeros(b - a)
        ts = t[a:b]
        for f in (root * 2, tones[0] / 2, tones[1] / 2, tones[2] / 2):
            for det in (-.006, .006):
                ph = (f * (1 + det) * ts) % 1.0
                seg += 2 * ph - 1
        fade = int(.08 * SR)
        seg[:fade] *= np.linspace(0, 1, fade); seg[-fade:] *= np.linspace(1, 0, fade)
        pad[a:b] += seg
    pad = filt(pad, 90, 620) * (0.8 + .2 * np.sin(2 * np.pi * .23 * t))
    pad /= np.max(np.abs(pad)) + 1e-9
    padgain = np.array([{'intro': .9, 'groove': .45, 'tense': .9, 'hits': .5, 'full': .5, 'desk': .8, 'bright': .5, 'end': 0}[section(x)] for x in np.arange(0, N, 2400) / SR])
    pad *= np.interp(np.arange(N), np.arange(0, N, 2400), padgain)
    mus = drums * .8 + bass + mel + np.vstack([pad, pad]) * .16
    # 'hits' sections: the beat drops out so the hit lands
    g = np.array([.16 if section(x) == 'hits' else 1.0 for x in np.arange(0, N, 480) / SR])
    g = np.interp(np.arange(N), np.arange(0, N, 480), np.convolve(g, np.ones(5) / 5, mode='same'))
    return mus * g


# ---------- effects ----------
def zap():
    t = tt(.11)
    gate = (np.sin(2 * np.pi * 70 * t) > 0).astype(float)
    return filt(rng.uniform(-1, 1, len(t)), 900, 7000) * gate * np.exp(-t * 16) * .8 + np.sin(2 * np.pi * np.cumsum(2400 * np.exp(-t * 30)) / SR) * np.exp(-t * 30) * .4


def buzz():
    t = tt(.2)
    return (np.tanh(4 * np.sin(2 * np.pi * 130 * t)) * .5 + filt(np.sign(np.sin(2 * np.pi * 130 * t)), 300, 2500) * .25) * np.minimum(1, (0.2 - t) / .03)


def tick():
    t = tt(.05)
    return filt(rng.uniform(-1, 1, len(t)), 1500, 8000) * np.exp(-t * 90) * .8


def effects():
    fx = np.zeros((2, N))
    cuts = [s['t0'] for s in C['shots'][1:]]
    for i, c in enumerate(cuts):                       # every cut: a short air sound into it and a click on it
        w = whoosh(.3)
        add(fx, w, c - len(w) / SR * .72, .24, (.5, -.5)[i % 2])
        add(fx, tick(), c - .005, .22, (-.3, .3)[i % 2])
    for t, size, g in CUES['impact']:
        add(fx, impact(size), t, g)
    for t in CUES['pop']:
        add(fx, pop(), t, .26)
    for t, g in CUES['ding']:
        add(fx, ding(), t, g)
    for t, g in CUES['buzz']:
        add(fx, buzz(), t, g)
    for a, b in CUES['riser']:
        add(fx, riser(b - a), a, .3)
    for t0, n, step in CUES['count']:
        for k in range(n):
            add(fx, beep(700 + 55 * k, .03, 90), t0 + k * step, .1)
    return fx


# ---------- game sound from the two player clips ----------
def clip(src, ss, dur):
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-ss', '%.3f' % ss, '-t', '%.3f' % dur, '-i', os.path.join(HERE, src), '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True).stdout
    x = np.frombuffer(raw, dtype=np.float32).astype(np.float64)
    if len(x) < SR // 10:
        return np.zeros(1)
    x = filt(x, 160, 9000)
    f = int(.03 * SR)
    x[:f] *= np.linspace(0, 1, f); x[-f:] *= np.linspace(1, 0, f)
    return x / (np.percentile(np.abs(x), 99.5) + 1e-9)


def game():
    return np.zeros((2, N))


def db(x):
    return 20 * np.log10(np.sqrt(np.mean(x ** 2)) + 1e-9)


V3 = os.path.dirname(os.path.abspath(__file__))
MOOD = {'tense': 'tense', 'hits': 'tense', 'desk': 'tense', 'intro': 'tense', 'groove': 'drive', 'full': 'drive', 'bright': 'light'}


def decode(path):
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', path, '-f', 'f32le', '-ac', '2', '-ar', str(SR), '-'], capture_output=True, timeout=120).stdout
    return np.frombuffer(raw, dtype=np.float32).reshape(-1, 2).T.astype(np.float64) if raw else np.zeros((2, 0))


def recorded():
    """A real track for this video, laid over its length, or (None, None). The mood is what most of the video's time is
    (its sections); among the tracks of that mood, the ones used by the last videos are passed over."""
    try:
        lib = json.load(open(os.path.join(V3, 'music.json'), encoding='utf-8'))
    except Exception:  # noqa: BLE001
        return None, None
    span = {}
    for (a, k), (b, _) in zip(SECTIONS, SECTIONS[1:]):
        span[MOOD.get(k, 'drive')] = span.get(MOOD.get(k, 'drive'), 0) + max(0, min(b, END) - a)
    mood = max(span, key=span.get) if span else 'drive'
    used_f = os.path.join(V3, 'store', 'music_used.json')
    try:
        used = json.load(open(used_f, encoding='utf-8'))
    except Exception:  # noqa: BLE001
        used = []
    pool = [t for t in lib['tracks'] if t['mood'] == mood] or lib['tracks']
    fresh = [t for t in pool if t['file'] not in used[-3:]] or pool
    key = sum(ord(c) for c in C['plan'].get('title', '') or HERE)
    order = fresh[key % len(fresh):] + fresh[:key % len(fresh)] + [t for t in lib['tracks'] if t not in fresh]
    for t in order:
        path = os.path.join(V3, 'store', 'music', t['file'])
        if not os.path.isfile(path):                       # another machine (GitHub): the track is fetched once
            try:
                os.makedirs(os.path.dirname(path), exist_ok=True)
                subprocess.run(['curl', '-s', '-A', 'Mozilla/5.0', '-o', path, lib['url'] % t['title'].replace(' ', '%20')], timeout=120)
            except Exception:  # noqa: BLE001
                continue
        x = decode(path) if os.path.isfile(path) else np.zeros((2, 0))
        if x.shape[1] < SR * 20:
            continue
        c = np.concatenate([[0.0], np.cumsum(x.mean(0) ** 2)])          # loudness over a quarter of a second (running sum: a convolution this long takes minutes)
        w = SR // 4
        e = np.sqrt(np.maximum(0, (c[w:] - c[:-w]) / w))
        start = int(np.argmax(e > .5 * np.median(e[e > 1e-4]))) if np.any(e > 1e-4) else 0      # skip a quiet intro: the video starts on the music
        x = x[:, start:]
        while x.shape[1] < N:                              # a short track loops, crossfaded
            f = SR // 2
            tail, head = x[:, -f:] * np.linspace(1, 0, f), x[:, :f] * np.linspace(0, 1, f)
            x = np.hstack([x[:, :-f], tail + head, x[:, f:]])
        x = x[:, :N].copy()
        x[:, :int(.25 * SR)] *= np.linspace(0, 1, int(.25 * SR))
        fo = int(1.6 * SR)
        x[:, -fo:] *= np.linspace(1, 0, fo)
        for a, k in SECTIONS:                              # the track follows the video: quieter on the tense parts, fuller on the hits
            if k in ('tense', 'desk'):
                g = .8
            elif k in ('full', 'hits', 'bright'):
                g = 1.1
            else:
                continue
            nxt = next((b for b, _ in SECTIONS if b > a), END)
            i0, i1 = int(a * SR), min(N, int(nxt * SR))
            x[:, i0:i1] *= g
        json.dump((used + [t['file']])[-8:], open(used_f, 'w', encoding='utf-8'))
        json.dump({'title': t['title'], 'credit': lib['credit'] % t['title'], 'mood': mood}, open(os.path.join(HERE, 'music_used.json'), 'w', encoding='utf-8'), ensure_ascii=False)
        return x, t
    return None, None


def main():
    with wave.open(os.path.join(HERE, 'vo.wav')) as w:
        vo = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float64) / 32768
    vo = np.pad(vo, (0, max(0, N - len(vo))))[:N]
    ref = np.sqrt(np.mean(vo[np.abs(vo) > .02] ** 2))
    for l in C['lines']:                                   # the story's own clip speaking (a bite): at the narrator's level; the music ducks under it the same way
        b = l.get('bite')
        if not b:
            continue
        x = clip(b['src'], b['ss'], b['dur'])
        if len(x) < SR // 2:
            print('bite %s: the clip gave no sound' % l['id']); continue
        x *= ref / (np.sqrt(np.mean(x[np.abs(x) > .02] ** 2)) + 1e-9)
        for w in l.get('words') or []:                     # a swear word of the clip: its seconds become a bleep (tts.SWEAR)
            if w.get('bleep'):
                a0, a1 = max(0, int((w['s'] - b['at'] - .03) * SR)), min(len(x), int((w['e'] - b['at'] + .03) * SR))
                if a1 > a0:
                    t = np.arange(a1 - a0) / SR
                    edge = np.minimum(1, np.minimum(t, t[::-1]) / .01)
                    x[a0:a1] = .35 * ref / .7 * np.sin(2 * np.pi * 1000 * t) * edge
        i = int(b['at'] * SR)
        n = max(0, min(len(x), len(vo) - i))
        vo[i:i + n] += x[:n]
        print('bite %s at %.2f s: %.2f s of %s from its second %.2f' % (l['id'], b['at'], b['dur'], b['asset'], b['ss']))
    vo = filt(vo, 85, None, shelf=(3800, 3.0))
    e = env_follow(vo, .005, .12)
    vo *= np.where(e > .16, (.16 / np.maximum(e, 1e-6)) ** .45, 1.0)          # gentle levelling
    vo /= np.max(np.abs(vo)) + 1e-9
    duck = env_follow(vo, .03, .28)
    duck = np.clip((duck - .05) / .22, 0, 1)
    mus, track = recorded()
    if mus is None:
        mus = music()
        print('music: no recorded track could be read; the code-made music plays')
    else:
        print('music: "%s" (%s), real recording' % (track['title'], track['mood']))
    fx, gm = effects(), game()
    mus /= np.max(np.abs(mus)) + 1e-9
    speaking = duck > .5
    target_mus = db(vo[speaking]) - 13.5
    mus *= 10 ** ((target_mus - db(mus[:, speaking])) / 20)
    mus *= 1 - .36 * duck
    gm *= 1 - .4 * duck
    fx *= .9
    mix = np.vstack([vo, vo]) * .92 + mus + fx + gm
    mix = np.vstack([filt(mix[0], 38, None), filt(mix[1], 38, None)])
    mix = np.tanh(mix * 1.15) / np.tanh(1.15)
    mix *= .89 / (np.max(np.abs(mix)) + 1e-9)
    f = int(.02 * SR)
    mix[:, -f:] *= np.linspace(1, 0, f)
    mix = mix[:, :int(END * SR)]
    with wave.open(os.path.join(HERE, 'mix.wav'), 'wb') as w:
        w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((mix.T * 32767).astype(np.int16).tobytes())
    print('length %.2fs' % (mix.shape[1] / SR))
    print('voice rms while speaking %.1f dB | music under voice %.1f dB | game under voice %.1f dB | effects peak %.2f' % (
        db(vo[speaking] * .92), db(mus[:, speaking]), db(gm[:, speaking]), np.max(np.abs(fx))))
    quiet = ~speaking[:mix.shape[1]]
    print('share of time with voice %.0f%% | mix rms in the gaps %.1f dB | mix rms overall %.1f dB' % (100 * speaking.mean(), db(mix[:, quiet]), db(mix)))


if __name__ == '__main__':
    main()
