"""THE FALLBACK VOICE: Kokoro (open-source, runs on this machine, no service) with word timings, used by tts.py when the
online voice refuses. Runs in its own Python (config.local.json: "voice_python"), because the model's packages need
Python 3.11 here.  usage: kokoro_say.py <text> <out.wav> <out.json> <voice> <speed>   or   kokoro_say.py <jobs.json>"""
import json
import sys

import numpy as np
import soundfile as sf


PIPE = {}


def say(pipe, text, out_wav, out_json, voice='am_michael', speed='1.0'):
    audio, words, t = [], [], 0.0
    for r in pipe(text, voice=voice, speed=float(speed)):
        a = r.audio.numpy() if hasattr(r.audio, 'numpy') else np.asarray(r.audio)
        for tk in (r.tokens or []):
            s, e, w = getattr(tk, 'start_ts', None), getattr(tk, 'end_ts', None), str(getattr(tk, 'text', '')).strip()
            if s is not None and e is not None and any(c.isalnum() for c in w):
                words.append({'w': w, 's': round(t + float(s), 3), 'd': round(max(0.05, float(e) - float(s)), 3)})
        audio.append(a.astype(np.float32))
        t += len(a) / 24000.0
    sf.write(out_wav, np.concatenate(audio) if audio else np.zeros(2400, dtype=np.float32), 24000)
    json.dump(words, open(out_json, 'w', encoding='utf-8'), ensure_ascii=False)
    return len(words)


def main(*argv):
    from kokoro import KPipeline
    pipe = KPipeline(lang_code='a', repo_id='hexgrad/Kokoro-82M')
    if len(argv) == 1 and argv[0].endswith('.json'):        # a batch: [{"text","wav","json","voice","speed"}], the model loaded once
        jobs = json.load(open(argv[0], encoding='utf-8'))
        for j in jobs:
            j['words'] = say(pipe, j['text'], j['wav'], j['json'], j.get('voice', 'am_michael'), str(j.get('speed', '1.0')))
        json.dump(jobs, open(argv[0], 'w', encoding='utf-8'), ensure_ascii=False)
        print(sum(j['words'] for j in jobs))
    else:
        print(say(pipe, *argv))


if __name__ == '__main__':
    main(*sys.argv[1:])
