"""THE VOICE: one take per script line (edge-tts with word timings), trimmed and laid end to end with chosen pauses.
Writes vo.wav (48 kHz mono) and timeline.json (every line and word with its time in the final video); every cut and
every graphic is placed from those times. A script that comes out a little under the minimum length is spoken once
more, a little slower (never slower than a natural pace), instead of losing the video.  usage: tts.py <work folder>"""
import asyncio
import json
import os
import subprocess
import sys
import time
import wave

import numpy as np

import ai

SR = 48000
LEAD = 0.10          # silence before the first word
SLOWEST = -4         # per cent: the slowest the voice may go


async def one(takes, i, text, rate, voice):
    import edge_tts
    mp3, meta = os.path.join(takes, '%02d.mp3' % i), os.path.join(takes, '%02d.json' % i)
    try:
        com = edge_tts.Communicate(text, voice, rate=rate, boundary='WordBoundary')
    except TypeError:
        com = edge_tts.Communicate(text, voice, rate=rate)
    words = []
    with open(mp3, 'wb') as f:
        async for chunk in com.stream():
            if chunk['type'] == 'audio':
                f.write(chunk['data'])
            elif chunk['type'] == 'WordBoundary':
                words.append({'w': chunk['text'], 's': chunk['offset'] / 1e7, 'd': chunk['duration'] / 1e7})
    json.dump(words, open(meta, 'w', encoding='utf-8'), ensure_ascii=False)
    return len(words)


def load(mp3):
    if not os.path.isfile(mp3) and os.path.isfile(mp3[:-4] + '.wav'):      # a take by the fallback voice
        mp3 = mp3[:-4] + '.wav'
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', mp3, '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True).stdout
    return np.frombuffer(raw, dtype=np.float32).copy()


def have_take(takes, i, text, rate):
    """A take spoken before, for the same words at the same rate, is kept (a stopped run does not speak twice)."""
    tag = os.path.join(takes, '%02d.txt' % i)
    ok = os.path.isfile(os.path.join(takes, '%02d.json' % i)) and (os.path.isfile(os.path.join(takes, '%02d.mp3' % i)) or os.path.isfile(os.path.join(takes, '%02d.wav' % i)))
    return ok and os.path.isfile(tag) and open(tag, encoding='utf-8').read() == '%s|%s' % (rate, text)


def mark_take(takes, i, text, rate):
    open(os.path.join(takes, '%02d.txt' % i), 'w', encoding='utf-8').write('%s|%s' % (rate, text))


def fallback_batch(takes, jobs):
    """The lines still to speak, by the local voice (kokoro_say.py in its own Python, the model loaded once): every video
    stopped on one free service's 403 otherwise. jobs: [(i, text, rate)]. -> the set of i that were spoken."""
    fb = ai.CONFIG.get('voice_fallback') or {}
    py = fb.get('python') or ai.CONFIG.get('voice_python', '')
    if not py or not os.path.isfile(py) or not jobs:
        return set()
    batch = []
    for i, text, rate in jobs:
        wav, meta = os.path.join(takes, '%02d.wav' % i), os.path.join(takes, '%02d.json' % i)
        for f in (wav, meta, os.path.join(takes, '%02d.mp3' % i)):
            if os.path.exists(f):
                os.remove(f)
        batch.append({'i': i, 'text': text, 'wav': wav, 'json': meta, 'voice': fb.get('voice', 'am_michael'), 'speed': '%.3f' % (1 + rate / 100.0)})
    jfile = os.path.join(takes, 'fallback_jobs.json')
    json.dump(batch, open(jfile, 'w', encoding='utf-8'), ensure_ascii=False)
    r = subprocess.run([py, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'kokoro_say.py'), jfile], capture_output=True, text=True, timeout=900)
    if r.returncode != 0:
        print('voice: the local voice failed: %s' % ' '.join(r.stderr.split())[-220:], flush=True)
        return set()
    return {j['i'] for j in json.load(open(jfile, encoding='utf-8')) if j.get('words', 0) > 0 and os.path.isfile(j['json'])}


BUDGET = {'t0': time.time(), 's': None}


def over():
    return BUDGET['s'] is not None and time.time() - BUDGET['t0'] > BUDGET['s']


def speak(takes, lines):
    spoken = {i for i, (lid, text, rate, _) in enumerate(lines) if have_take(takes, i, text, rate)}
    refused = []

    async def synth():
        for i, (lid, text, rate, _) in enumerate(lines):
            if i in spoken:
                continue
            if refused or over():                         # the service is refusing: the rest goes to the local voice; or the time is up
                break
            for attempt in range(3):                      # the free voice service drops a request now and then
                try:
                    if await one(takes, i, text, '%+d%%' % rate, ai.CONFIG['voice']) > 0:
                        mark_take(takes, i, text, rate)
                        spoken.add(i)
                        break
                except Exception as e:  # noqa: BLE001
                    if attempt == 2:
                        refused.append(str(e)[:80])
                        break
                    await asyncio.sleep(2)
    asyncio.run(synth())
    left = [i for i in range(len(lines)) if i not in spoken]
    if left and refused:
        print('voice: the online voice refuses (%s); the local voice speaks %d line(s)' % (refused[0], len(left)), flush=True)
    while left and refused:
        if over():
            break
        chunk = left[:3]                                  # three lines at a time, so a time budget can stop between them
        got = fallback_batch(takes, [(i, lines[i][1], lines[i][2]) for i in chunk])
        if not got:
            raise SystemExit('voice failed on line %s: %s' % (lines[chunk[0]][0], refused[0]))
        for i in got:
            mark_take(takes, i, lines[i][1], lines[i][2])
        spoken |= got
        left = [i for i in left if i not in got]
    if left:
        if over():
            print('voice: stopped at the time budget with %d line(s) to speak; run again' % len(left), flush=True)
            raise SystemExit(3)
        raise SystemExit('voice failed on line %s: no fallback voice on this machine' % lines[left[0]][0])
    out, tl, t = [np.zeros(int(LEAD * SR), dtype=np.float32)], [], LEAD
    for i, (lid, text, rate, pause) in enumerate(lines):
        a = load(os.path.join(takes, '%02d.mp3' % i))
        words = json.load(open(os.path.join(takes, '%02d.json' % i), encoding='utf-8'))
        loud = np.where(np.abs(a) > 0.012)[0]
        s0, s1 = max(0, loud[0] - int(0.015 * SR)), min(len(a), loud[-1] + int(0.06 * SR))
        a = a[s0:s1]
        fade = int(0.008 * SR)
        a[:fade] *= np.linspace(0, 1, fade); a[-fade:] *= np.linspace(1, 0, fade)
        off = t - s0 / SR
        ws = [{'w': w['w'], 's': round(off + w['s'], 3), 'e': round(off + w['s'] + w['d'], 3)} for w in words]
        dur = len(a) / SR
        tl.append({'id': lid, 'text': text, 's': round(t, 3), 'e': round(t + dur, 3), 'words': ws})
        out.append(a); out.append(np.zeros(int(pause * SR), dtype=np.float32))
        t += dur + pause
    return np.concatenate(out), tl, t


def main(work):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    takes = os.path.join(work, 'takes'); os.makedirs(takes, exist_ok=True)
    lines = [[l['id'], l['text'], int(l.get('rate') or (10 if l['id'] in ('vote', 'site', 'final') else 12)),
              float(l.get('pause') or (0.5 if l['id'] == 'site' else 0.3 if l['id'] in ('vote', 'final') else 0.22))] for l in plan['lines']]
    need = ai.CONFIG['length']['min_s'] + 1.0
    rfile = os.path.join(takes, 'rates.json')
    if os.path.isfile(rfile):                             # a slower pace was chosen in an earlier run of this step: spoken at it from the first line
        for l, r in zip(lines, json.load(open(rfile, encoding='utf-8'))):
            l[2] = r
    vo, tl, t = speak(takes, lines)
    passes = len(json.load(open(rfile + '.passes', encoding='utf-8'))) if os.path.isfile(rfile + '.passes') else 0
    while t + 0.45 < need and passes < 2 and any(l[2] > SLOWEST for l in lines):      # short: once or twice more, slower by what is missing (a voice does not slow in proportion)
        silent = LEAD + sum(l[3] for l in lines)          # the pauses do not stretch: only the speech is slowed, by what the speech is missing
        slower = [max(SLOWEST, int((100 + r) * (t - silent) / max(1.0, need + 0.5 - silent) - 100) - (1 if passes else 0)) for _, _, r, _ in lines]
        if slower == [l[2] for l in lines]:
            break
        print('voice: %.1f s is under the minimum; spoken again at %+d%% instead of %+d%%' % (t + 0.45, slower[0], lines[0][2]), flush=True)
        json.dump(slower, open(rfile, 'w', encoding='utf-8'))
        passes += 1
        json.dump([1] * passes, open(rfile + '.passes', 'w', encoding='utf-8'))
        for l, r in zip(lines, slower):
            l[2] = r
        vo, tl, t = speak(takes, lines)
    vo = vo / (np.max(np.abs(vo)) + 1e-9) * 0.89
    with wave.open(os.path.join(work, 'vo.wav'), 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((vo * 32767).astype(np.int16).tobytes())
    json.dump({'total': round(t, 3), 'lines': tl}, open(os.path.join(work, 'timeline.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('voice: %.1f s, %d lines, %d words' % (t, len(tl), sum(len(l['words']) for l in tl)), flush=True)
    return t


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    if len(sys.argv) > 2:
        BUDGET['s'] = float(sys.argv[2])
    main(sys.argv[1])
