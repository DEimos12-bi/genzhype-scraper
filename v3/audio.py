"""THE SOUND: the voice, an original music bed and effects (all made here in code, nothing licensed), placed by the cue
list that shots.py wrote from the graphics. Writes mix.wav (48 kHz stereo) and prints the levels.
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


def main():
    with wave.open(os.path.join(HERE, 'vo.wav')) as w:
        vo = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float64) / 32768
    vo = np.pad(vo, (0, max(0, N - len(vo))))[:N]
    vo = filt(vo, 85, None, shelf=(3800, 3.0))
    e = env_follow(vo, .005, .12)
    vo *= np.where(e > .16, (.16 / np.maximum(e, 1e-6)) ** .45, 1.0)          # gentle levelling
    vo /= np.max(np.abs(vo)) + 1e-9
    duck = env_follow(vo, .03, .28)
    duck = np.clip((duck - .05) / .22, 0, 1)
    mus, fx, gm = music(), effects(), game()
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
