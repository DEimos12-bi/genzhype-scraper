"""v2 voice: one edge-tts take per plan line (word timings), trimmed, laid end to end with the plan's pauses.
The owner's hand-made method (tts.py of the reference projects), driven by plan.json instead of a hard-coded LINES table.
usage: tts.py <workdir>   (reads <workdir>/plan.json; writes takes/, vo.wav, timeline.json)"""
import asyncio
import json
import os
import subprocess
import sys
import wave

import numpy as np

VOICE = 'en-US-AndrewMultilingualNeural'
SR = 48000
LEAD = 0.10


def say(*a):
    print(*a, flush=True)


async def one(takes, i, text, rate):
    import edge_tts
    mp3, meta = os.path.join(takes, '%02d.mp3' % i), os.path.join(takes, '%02d.json' % i)
    try:
        com = edge_tts.Communicate(text, VOICE, rate=rate, boundary='WordBoundary')
    except TypeError:
        com = edge_tts.Communicate(text, VOICE, rate=rate)
    words = []
    with open(mp3, 'wb') as f:
        async for chunk in com.stream():
            if chunk['type'] == 'audio':
                f.write(chunk['data'])
            elif chunk['type'] == 'WordBoundary':
                words.append({'w': chunk['text'], 's': chunk['offset'] / 1e7, 'd': chunk['duration'] / 1e7})
    json.dump(words, open(meta, 'w', encoding='utf-8'), ensure_ascii=False)
    return len(words)


async def synth(takes, lines):
    os.makedirs(takes, exist_ok=True)
    for i, ln in enumerate(lines):
        for attempt in range(3):
            try:
                n = await one(takes, i, ln['text'], ln.get('rate', '+10%'))
                say('take', i, ln['id'], n, 'words')
                break
            except Exception as e:  # noqa: BLE001
                say('take', i, 'failed', attempt, str(e)[:120])
                await asyncio.sleep(2)
        else:
            raise SystemExit('edge-tts failed on line %d' % i)


def load(mp3):
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', mp3, '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True).stdout
    return np.frombuffer(raw, dtype=np.float32).copy()


def build(work, lines):
    takes = os.path.join(work, 'takes')
    out, tl, t = [np.zeros(int(LEAD * SR), dtype=np.float32)], [], LEAD
    for i, ln in enumerate(lines):
        a = load(os.path.join(takes, '%02d.mp3' % i))
        words = json.load(open(os.path.join(takes, '%02d.json' % i), encoding='utf-8'))
        env = np.abs(a)
        loud = np.where(env > 0.012)[0]
        if len(loud) == 0:
            raise SystemExit('silent take %d' % i)
        s0, s1 = max(0, loud[0] - int(0.015 * SR)), min(len(a), loud[-1] + int(0.06 * SR))
        a = a[s0:s1]
        fade = int(0.008 * SR)
        a[:fade] *= np.linspace(0, 1, fade); a[-fade:] *= np.linspace(1, 0, fade)
        off = t - s0 / SR
        ws = [{'w': w['w'], 's': round(off + w['s'], 3), 'e': round(off + w['s'] + w['d'], 3)} for w in words]
        dur = len(a) / SR
        tl.append({'id': ln['id'], 'text': ln['text'], 's': round(t, 3), 'e': round(t + dur, 3), 'words': ws})
        pause = float(ln.get('pause', 0.22))
        out.append(a); out.append(np.zeros(int(pause * SR), dtype=np.float32))
        t += dur + pause
    vo = np.concatenate(out)
    vo = vo / (np.max(np.abs(vo)) + 1e-9) * 0.89
    with wave.open(os.path.join(work, 'vo.wav'), 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((vo * 32767).astype(np.int16).tobytes())
    json.dump({'total': round(t, 3), 'lines': tl}, open(os.path.join(work, 'timeline.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    say('voice total %.2f s, %d lines' % (t, len(tl)))
    return t


if __name__ == '__main__':
    work = sys.argv[1]
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    lines = plan['lines']
    asyncio.run(synth(os.path.join(work, 'takes'), lines))
    build(work, lines)
