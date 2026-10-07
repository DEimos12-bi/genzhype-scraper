"""THE VOICE: one take per script line (edge-tts with word timings), trimmed and laid end to end with chosen pauses.
Writes vo.wav (48 kHz mono) and timeline.json (every line and word with its time in the final video); every cut and
every graphic is placed from those times.  usage: tts.py <work folder>"""
import asyncio
import json
import os
import subprocess
import sys
import wave

import numpy as np

import ai

SR = 48000
LEAD = 0.10          # silence before the first word


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
    raw = subprocess.run(['ffmpeg', '-v', 'error', '-i', mp3, '-f', 'f32le', '-ac', '1', '-ar', str(SR), '-'], capture_output=True).stdout
    return np.frombuffer(raw, dtype=np.float32).copy()


def main(work):
    plan = json.load(open(os.path.join(work, 'plan.json'), encoding='utf-8'))
    takes = os.path.join(work, 'takes'); os.makedirs(takes, exist_ok=True)
    lines = [(l['id'], l['text'], l.get('rate') or ('+10%' if l['id'] in ('vote', 'site') else '+12%'), 0.5 if l['id'] == 'site' else 0.3 if l['id'] == 'vote' else 0.22) for l in plan['lines']]

    async def synth():
        for i, (lid, text, rate, _) in enumerate(lines):
            for attempt in range(3):                      # the free voice service drops a request now and then
                try:
                    if await one(takes, i, text, rate, ai.CONFIG['voice']) > 0:
                        break
                except Exception as e:  # noqa: BLE001
                    if attempt == 2:
                        raise SystemExit('voice failed on line %s: %s' % (lid, str(e)[:120]))
                    await asyncio.sleep(2)
    asyncio.run(synth())
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
    vo = np.concatenate(out)
    vo = vo / (np.max(np.abs(vo)) + 1e-9) * 0.89
    with wave.open(os.path.join(work, 'vo.wav'), 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes((vo * 32767).astype(np.int16).tobytes())
    json.dump({'total': round(t, 3), 'lines': tl}, open(os.path.join(work, 'timeline.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('voice: %.1f s, %d lines, %d words' % (t, len(tl), sum(len(l['words']) for l in tl)), flush=True)
    return t


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv[1])
